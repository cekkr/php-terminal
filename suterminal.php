<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if(!isset($_GET['pwd']) || $_GET['pwd'] != 'fdisbf343q$£$(5') exit;

$sudoPassword = isset($_GET['sudo']) ? $_GET['sudo'] : null;

session_start();

// Directory per i file di processo
$processDir = sys_get_temp_dir() . '/terminal_processes';
if (!file_exists($processDir)) {
    mkdir($processDir, 0777, true);
}

// Funzione per preparare il comando con sudo se necessario
function prepareCommand($command, $sudoPassword) {
    // Sanifichiamo il comando per evitare injection
    $command = escapeshellcmd($command);
    
    // Configuriamo l'ambiente di esecuzione
    $envSetup = 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
    
    if (strpos($command, 'sudo') === 0 && $sudoPassword) {
        // Per comandi sudo, usiamo expect per gestire l'input della password
        $scriptContent = sprintf('%s && echo "%s" | sudo -S bash -c %s', 
            $envSetup,
            $sudoPassword, 
            escapeshellarg(substr($command, 5))
        );
        return $scriptContent;
    }
    
    // Per comandi normali, li eseguiamo con bash e l'ambiente configurato
    return sprintf('%s && bash -c %s', $envSetup, escapeshellarg($command));
}

// Gestione dell'avvio di un nuovo comando
if (isset($_POST['command'])) {
    header('Content-Type: application/json');
    
    $command = $_POST['command'];
    $processId = uniqid();
    $outputFile = "$processDir/$processId.out";
    $pidFile = "$processDir/$processId.pid";
    
    // Prepara il comando
    $execCommand = prepareCommand($command, $sudoPassword);
    
    // Esegui il comando reindirizzando l'output
    $fullCommand = sprintf('%s > %s 2>&1 & echo $! > %s', 
        $execCommand, 
        escapeshellarg($outputFile), 
        escapeshellarg($pidFile)
    );
    
    exec($fullCommand);
    
    // Verifica che il processo sia stato avviato correttamente
    if (file_exists($pidFile)) {
        $pid = intval(file_get_contents($pidFile));
        if ($pid > 0) {
            echo json_encode([
                'processId' => $processId,
                'status' => 'started',
                'pid' => $pid
            ]);
            exit;
        }
    }
    
    echo json_encode(['error' => 'Failed to execute command']);
    exit;
}

// Gestione del polling per l'output
if (isset($_POST['poll']) && isset($_POST['processId'])) {
    header('Content-Type: application/json');
    
    $processId = $_POST['processId'];
    $outputFile = "$processDir/$processId.out";
    $pidFile = "$processDir/$processId.pid";
    
    // Verifica l'esistenza dei file necessari
    if (!file_exists($pidFile)) {
        echo json_encode(['error' => 'Process not found']);
        exit;
    }
    
    // Leggi il PID e verifica se il processo è ancora in esecuzione
    $pid = intval(file_get_contents($pidFile));
    $isRunning = $pid > 0 && file_exists("/proc/$pid");
    
    // Leggi l'output dal file
    $output = '';
    if (file_exists($outputFile)) {
        $output = file_get_contents($outputFile);
        if (!$isRunning) {
            // Se il processo è terminato, prendiamo tutto l'output e puliamo
            unlink($outputFile);
            unlink($pidFile);
        } else {
            // Se il processo è ancora in esecuzione, puliamo il file per il prossimo polling
            file_put_contents($outputFile, '');
        }
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
        body {
            background: #1a1a1a;
            color: #ffffff;
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        #terminal {
            background: #000000;
            color: #00ff00;
            font-family: 'Courier New', monospace;
            padding: 15px;
            height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
            margin-bottom: 15px;
            border: 1px solid #333;
            border-radius: 5px;
        }
        #command {
            width: calc(100% - 20px);
            padding: 10px;
            margin-top: 10px;
            font-family: 'Courier New', monospace;
            background: #333;
            color: #fff;
            border: 1px solid #444;
            border-radius: 3px;
        }
        .error-text {
            color: #ff4444;
        }
        .command-text {
            color: #00ffff;
            font-weight: bold;
        }
        .sudo-enabled {
            color: #ffff00;
            font-size: 0.9em;
            margin-bottom: 10px;
            padding: 5px;
            background: #333;
            border-radius: 3px;
            display: inline-block;
        }
    </style>
</head>
<body>
    <?php if($sudoPassword): ?>
    <div class="sudo-enabled">🔑 Modalità sudo attiva</div>
    <?php endif; ?>
    <div id="terminal"></div>
    <input type="text" id="command" placeholder="Inserisci un comando..." autofocus>

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
                appendToTerminal(`Errore di comunicazione: ${error.message}`, true);
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
            commandInput.focus();
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
                }, 250); // Polling ogni 250ms per un migliore bilanciamento
            })
            .catch(error => {
                appendToTerminal(`Errore di esecuzione: ${error.message}`, true);
                commandInput.disabled = false;
            });
        }

        commandInput.addEventListener('keypress', (event) => {
            if (event.key === 'Enter' && !commandInput.disabled) {
                const command = commandInput.value.trim();
                if (command) {
                    executeCommand(command);
                    commandInput.value = '';
                }
            }
        });

        // Command history management
        let commandHistory = [];
        let historyIndex = -1;

        commandInput.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (historyIndex < commandHistory.length - 1) {
                    historyIndex++;
                    commandInput.value = commandHistory[historyIndex];
                }
            } else if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (historyIndex > -1) {
                    historyIndex--;
                    commandInput.value = historyIndex >= 0 ? commandHistory[historyIndex] : '';
                }
            }
        });

        commandInput.addEventListener('keypress', (event) => {
            if (event.key === 'Enter' && commandInput.value.trim()) {
                commandHistory.unshift(commandInput.value);
                historyIndex = -1;
            }
        });

        // Messaggio iniziale
        appendToTerminal('Terminal Web pronto. Inserisci un comando...');
        <?php if($sudoPassword): ?>
        appendToTerminal('Modalità sudo attiva - i comandi sudo verranno gestiti automaticamente');
        <?php endif; ?>
    </script>
</body>
</html>