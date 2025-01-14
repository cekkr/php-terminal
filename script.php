<?php
require_once 'vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class Terminal implements MessageComponentInterface {
    protected $clients;
    protected $processes = [];

    public function __construct() {
        $this->clients = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        
        if (!isset($data['command'])) {
            return;
        }

        $command = $data['command'];
        $descriptorspec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "w")   // stderr
        );

        $process = proc_open($command, $descriptorspec, $pipes);
        
        if (is_resource($process)) {
            // Make stdout non-blocking
            stream_set_blocking($pipes[1], 0);
            stream_set_blocking($pipes[2], 0);
            
            $this->processes[$from->resourceId] = [
                'process' => $process,
                'pipes' => $pipes
            ];
            
            // Start monitoring output
            while (true) {
                $read = [$pipes[1], $pipes[2]];
                $write = null;
                $except = null;
                
                if (stream_select($read, $write, $except, 0, 200000)) {
                    foreach ($read as $pipe) {
                        $output = fread($pipe, 4096);
                        if ($output !== false && strlen($output) > 0) {
                            $from->send(json_encode([
                                'type' => ($pipe === $pipes[2]) ? 'stderr' : 'stdout',
                                'output' => $output
                            ]));
                        }
                    }
                }
                
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
            }
            
            // Clean up
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            unset($this->processes[$from->resourceId]);
        }
    }

    public function onClose(ConnectionInterface $conn) {
        if (isset($this->processes[$conn->resourceId])) {
            $processData = $this->processes[$conn->resourceId];
            foreach ($processData['pipes'] as $pipe) {
                fclose($pipe);
            }
            proc_terminate($processData['process']);
            proc_close($processData['process']);
            unset($this->processes[$conn->resourceId]);
        }
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }
}

// Frontend HTML
?>
<!DOCTYPE html>
<html>
<head>
    <title>Web Terminal</title>
    <style>
        #terminal {
            background: black;
            color: #00ff00;
            font-family: monospace;
            padding: 10px;
            height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
        }
        #command {
            width: 100%;
            padding: 5px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div id="terminal"></div>
    <input type="text" id="command" placeholder="Enter command...">

    <script>
        const terminal = document.getElementById('terminal');
        const commandInput = document.getElementById('command');
        const ws = new WebSocket('ws://localhost:8080');

        ws.onopen = () => {
            appendToTerminal('Connected to terminal server\n');
        };

        ws.onmessage = (event) => {
            const data = JSON.parse(event.data);
            appendToTerminal(data.output);
        };

        ws.onclose = () => {
            appendToTerminal('Disconnected from terminal server\n');
        };

        commandInput.addEventListener('keypress', (event) => {
            if (event.key === 'Enter') {
                const command = commandInput.value;
                ws.send(JSON.stringify({ command: command }));
                appendToTerminal(`$ ${command}\n`);
                commandInput.value = '';
            }
        });

        function appendToTerminal(text) {
            terminal.textContent += text;
            terminal.scrollTop = terminal.scrollHeight;
        }
    </script>
</body>
</html>

<?php
// WebSocket server setup
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new Terminal()
        )
    ),
    8080
);

$server->run();
?>