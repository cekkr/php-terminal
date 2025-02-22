<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Verifica della password principale per l'accesso
if(!isset($_GET['pwd']) || $_GET['pwd'] != 'fdisbf343q$£$(5') exit;

// Verifica della password sudo se fornita
$sudoPassword = isset($_GET['sudo']) ? $_GET['sudo'] : null;

session_start();

// Directory per i file di processo e screen sessions
$processDir = sys_get_temp_dir() . '/terminal_processes';
if (!file_exists($processDir)) {
    mkdir($processDir, 0777, true);
}

// Funzione per preparare il comando con sudo se necessario
function prepareCommand($command, $sudoPassword) {
    // Verifica se il comando inizia con sudo
    if (strpos($command, 'sudo') === 0 && $sudoPassword) {
        // Crea uno script temporaneo per gestire il comando sudo
        $scriptFile = tempnam(sys_get_temp_dir(), 'sudo_script');
        $screenSession = uniqid('term_');
        
        // Prepara lo script che gestirà l'input della password
        $scriptContent = "#!/usr/bin/expect -f\n";
        $scriptContent .= "set timeout -1\n";
        $scriptContent .= "spawn screen -S $screenSession $command\n";
        $scriptContent .= "expect {\n";
        $scriptContent .= "    \"password for\" {\n";
        $scriptContent .= "        sleep 1\n";  // Attende 1 secondo prima di inviare la password
        $scriptContent .= "        send \"$sudoPassword\\r\"\n";
        $scriptContent .= "        exp_continue\n";
        $scriptContent .= "    }\n";
        $scriptContent .= "}\n";
        
        // Salva lo script con permessi appropriati
        file_put_contents($scriptFile, $scriptContent);
        chmod($scriptFile, 0700);
        
        // Restituisce il comando da eseguire
        $preparedCommand = "expect $scriptFile";
        
        // Programma la pulizia del file temporaneo
        register_shutdown_function(function() use ($scriptFile) {
            @unlink($scriptFile);
        });
        
        return array($preparedCommand, $screenSession);
    }
    
    // Per comandi non-sudo, usa screen normalmente
    $screenSession = uniqid('term_');
    return array("screen -S $screenSession $command", $screenSession);
}

// Gestione dell'avvio di un nuovo comando
if (isset($_POST['command'])) {
    header('Content-Type: application/json');
    
    $command = $_POST['command'];
    $processId = uniqid();
    $outputFile = "$processDir/$processId.out";
    
    // Prepara il comando con gestione sudo se necessario
    list($fullCommand, $screenSession) = prepareCommand($command, $sudoPassword);
    
    // Salva l'associazione tra processId e screenSession
    file_put_contents("$processDir/$processId.screen", $screenSession);
    
    // Esegui il comando reindirizzando l'output
    $fullCommand .= " 2>&1 | tee $outputFile";
    exec($fullCommand . " > /dev/null 2>&1 & echo $!");
    
    echo json_encode([
        'processId' => $processId,
        'status' => 'started'
    ]);
    exit;
}

// Gestione del polling per l'output
if (isset($_POST['poll']) && isset($_POST['processId'])) {
    header('Content-Type: application/json');
    
    $processId = $_POST['processId'];
    $outputFile = "$processDir/$processId.out";
    $screenFile = "$processDir/$processId.screen";
    
    if (!file_exists($screenFile)) {
        echo json_encode(['error' => 'Process not found']);
        exit;
    }
    
    $screenSession = file_get_contents($screenFile);
    
    // Verifica se la sessione screen è ancora attiva
    exec("screen -ls | grep $screenSession", $output, $return);
    $isRunning = ($return === 0);
    
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
        @unlink($screenFile);
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
        // Se è una sessione screen, terminala prima di rimuovere i file
        if (strpos($file, '.screen') !== false) {
            $screenSession = file_get_contents($file);
            exec("screen -S $screenSession -X quit");
        }
        @unlink($file);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Terminal Web Avanzato</title>
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
        .sudo-enabled {
            color: #ffff00;
            font-size: 0.8em;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <?php if($sudoPassword): ?>
    <div class="sudo-enabled">Modalità sudo attiva</div>
    <?php endif; ?>
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
                }, 100);
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
        appendToTerminal('Terminal Web Avanzato pronto. Inserisci un comando...');
        <?php if($sudoPassword): ?>
        appendToTerminal('Modalità sudo attiva - i comandi sudo verranno gestiti automaticamente');
        <?php endif; ?>
    </script>
</body>
</html>