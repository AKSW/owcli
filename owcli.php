#!/usr/bin/env php5
<?php
/**
 * OntoWiki command line client
 *
 * @author     Sebastian Tramp <tramp@informatik.uni-leipzig.de>
 * @copyright  Copyright (c) 2009-2010 {@link http://aksw.org AKSW}
 * @license    http://www.gnu.org/licenses/gpl.txt  GNU GENERAL PUBLIC LICENSE v2
 * @link       http://ontowiki.net/Projects/OntoWiki/CommandLineInterface
 */
class OntowikiCommandLineInterface {
    
    const NAME = 'The OntoWiki CLI';
    const VERSION = '0.4 tip';

    /* Required PEAR Packages */
    protected $pearPackages = array(
        'Console/Getargs.php',
        'Console/Table.php'
    );

    /* Command Line Parameter Config */
    protected $argConfig = array();
    /* Command Line Parameter Getarg Object */
    protected $args;
    /* Parsed Config Array */
    protected $config;
    /* the parsed config of the active wiki */
    protected $wikiConfig;
    /* the temp. filename for the input triple */
    protected $tmpTripleLocation = false;
    /* the input models as one big rdf/php structure */
    protected $inputModel = false;
    /* The target model of this owcli run */
    protected $selectedModel = false;
    /* The command execution queue */
    protected $commandList = array();
    /* The current command in the execution queue */
    protected $currentCommand;
    /* The current command ID in the execution queue */
    protected $currentCommandId = 0;
    /* array of smbs for every rpc server */
    protected $smd = array();
    /* messages for curl http status codes (http://de.php.net/manual/en/function.curl-getinfo.php) */
    protected $http_codes = array (
        100 => "Continue", 101 => "Switching Protocols", 200 => "OK", 201 => "Created",
        202 => "Accepted", 203 => "Non-Authoritative Information", 204 => "No Content",
        205 => "Reset Content", 206 => "Partial Content", 300 => "Multiple Choices",
        301 => "Moved Permanently", 302 => "Found", 303 => "See Other", 304 => "Not Modified",
        305 => "Use Proxy", 306 => "(Unused)", 307 => "Temporary Redirect", 400 => "Bad Request",
        401 => "Unauthorized", 402 => "Payment Required", 403 => "Forbidden", 404 => "Not Found",
        405 => "Method Not Allowed", 406 => "Not Acceptable", 407 => "Proxy Authentication Required",
        408 => "Request Timeout", 409 => "Conflict", 410 => "Gone", 411 => "Length Required",
        412 => "Precondition Failed", 413 => "Request Entity Too Large", 414 => "Request-URI Too Long",
        415 => "Unsupported Media Type", 416 => "Requested Range Not Satisfiable", 417 => "Expectation Failed",
        500 => "Internal Server Error", 501 => "Not Implemented", 502 => "Bad Gateway",
        503 => "Service Unavailable", 504 => "Gateway Timeout", 505 => "HTTP Version Not Supported",
    );


    public function __construct() {
        // load pear packages
        $this->checkPearPackages();
        // check command line parameters
        $this->checkCommandLineArguments();
        // check and initialize config file
        $this->checkConfig();
        // check and initialize addionally tools
        $this->checkTools();
        // select the model to work with
        $this->selectModel();
        // parse and check the input graph files
        $this->checkInputModels();

        $this->echoDebug('Everything ok, start to execute commands:');
        foreach ((array) $this->commandList as $command) {
            $this->currentCommand = $command;
            $result = $this->executeJsonRpc($command);
            if ($result) {
                $this->renderResult ($result);
            }
        }

        // delete the temp file
        if ($this->tmpTripleLocation) {
            unlink($this->tmpTripleLocation);
        }
    }

    /*
     * selects a model from different config options
     */
    protected function selectModel() {
        global $argv;
        // this is always set (cause of default model)
        $model = $this->args->getValue('model');

        // if a defaultmodel is set, use this (unless -m is given)
        if (isset($this->wikiConfig['defaultmodel'])) {
            $model = $this->wikiConfig['defaultmodel'];
        }

        // if model parameter is explicit given, of course use it ...
        if (array_search('-m' , $argv)) {
            $model = $this->args->getValue('model');
        }

        $this->echoDebug('selected model: '.$model);
        $this->selectedModel = $model;
    }

    /*
     * call redlands rapper to create rdf/json
     */
    protected function checkInputModels() {
        if (!$this->args->getValue('input')) {
            return;
        }

        if (is_array($this->args->getValue('input'))) {
            $files = $this->args->getValue('input');
        } else {
            $files = array ( 0 => $this->args->getValue('input') );
        }

        $tmpTripleLocation = tempnam (sys_get_temp_dir(), 'owcli-merged-input-');
        $this->tmpTripleLocation = $tmpTripleLocation;

        foreach ($files as $inputFile) {
            $this->echoDebug("checkInputModels: input file is now $inputFile");

            // we need to temp-save stdin models first
            if ($inputFile == '-') {
                $inputFile = tempnam (sys_get_temp_dir(), 'owcli-stdin-');
                $deleteFile = $inputFile;
                $tmpStdInHandle = fopen ($inputFile, "w");
                if ( !STDIN ) {
                    // if there is no piped input, ignore it
                    $this->echoDebug('checkInputModels: Can\'t open STDIN (ignored)');
                    break;
                } else {
                    while (!feof(STDIN)) {
                        fwrite($tmpStdInHandle, fgets(STDIN, 4096));
                    }
                    fclose($tmpStdInHandle);
                }
            }

            // all input files are merged to one big ntriple file
            $inputOptions = $this->args->getValue('inputOptions');
            $rapperParseCommand = "rapper $inputOptions $inputFile -q -o ntriples >>$tmpTripleLocation";
            $this->echoDebug("checkInputModels: $rapperParseCommand");
            system($rapperParseCommand, $rapperParseReturn);

            // delete the temp file for the STDIN input model
            if ($deleteFile) {
                unlink($deleteFile);
                unset ($deleteFile);
            }

            if ($rapperParseReturn != 0) {
                $this->echoError ('Error on parsing the input model!');
                $this->echoError ("($rapperParseCommand)");
                exit();
            }
        }

        $this->echoDebug("checkInputModels: All input models parsed and accepted");
        $this->inputModel = json_decode(`rapper $tmpTripleLocation -q -i ntriples -o json`, true);
    }

    /*
     * Renders a rpc result
     *
     * @param string $result  the result from an executeJsonRpc call
     */
    protected function renderResult($response) {
        if ($this->args->isDefined('raw')) {
            // raw output is easy ...
            echo $response . PHP_EOL;
        } else {
            // try to decode and look for the content to decide how to echo
            $decodedResult = json_decode($response, true);
            if (!$decodedResult) {
                // if decoding fails, something went wrong
                $this->echoError('Something went wrong, response was not json encoded (turn debug on to see more)');
                $this->echoDebug($response);
            } else {
                if ($decodedResult['error']) {
                    // if we have an rpc error, output is easy too
                    $error = $decodedResult['error'];
                    $this->echoError('JSONRPC Error '.$error['code'].': '.$error['message']);
                } elseif (isset($decodedResult['result'])) {
                    #var_dump($decodedResult['result']); die();
                    // different rendering for different results
                    $result = $decodedResult['result'];
                    if (is_array($result)) {
                        if (count($result) == 0) {
                            // e.g. on sparql queries without without result
                            echo 'Empty result' . PHP_EOL;
                        } elseif (!is_array($result[0])) {
                            // simply output for one-dimensional arrays
                            foreach ($result as $row) {
                                echo $row . PHP_EOL;
                            }
                        } else {
                            // table output for multidimensional arrays
                            echo $this->renderTable($result);
                        }
                    } elseif ( is_bool($result) ) {
                        // all simple result type are printed with echo
                        if ($result == true) {
                            echo $this->currentCommand .': success' . PHP_EOL;
                        } else {
                            echo $this->currentCommand .': failed' . PHP_EOL;
                        }
                    } elseif ( (is_numeric($result)) || (is_string($result)) ) {
                        // all simple result type are printed with echo
                        echo $result . PHP_EOL;
                    } else  {
                        print_r($result) .PHP_EOL;
                    }
                } else {
                    $this->echoError('Something went wrong, neither result nor error in response.');
                }
            }
        }
    }

    /*
     * Renders a table rpc result (e.g. for sparql results)
     *
     * @param string $result  the result from an executeJsonRpc call
     */
    protected function renderTable ($result) {
        $table = new Console_Table;

        #var_dump($result); die();
        $firstrow = true;
        foreach ($result as $row) {

            if ($firstrow == true) {
                $i=0;
                foreach ($row as $key => $var) {
                    $headrow[$i] = $key;
                    $i++;
                }
                $table->setHeaders ($headrow);
                $firstrow = false;
            }

            // prepare content row array
            $i=0;
            foreach ($row as $var) {
                $LabelRow[$i] = $var;
                $i++;
            }
            $table->addRow($LabelRow);
        }

        // output the table
        return $table->getTable();
   }

    /*
     * Retrieve a Service Mapping Description from the Server
     *
     * @param string $serverUrl  the URL of the JSONRPC Server
     */
    protected function getSMD($serverUrl) {
        $smb = curl_init();
        curl_setopt ($smb, CURLOPT_URL, $serverUrl . '?REQUEST_METHOD');
        curl_setopt ($smb, CURLOPT_USERAGENT, self::NAME . ' ' . self::VERSION);
        curl_setopt ($smb, CURLOPT_RETURNTRANSFER, true);
        curl_setopt ($smb, CURLOPT_CONNECTTIMEOUT, 30);
        $content = curl_exec($smb);
        $recodedContent = json_decode($content);
        if ($recodedContent) {
            $info = curl_getinfo($smb);
            if ($info['http_code'] != 200) {
                $this->echoError('Error on getSMD: '. $info['http_code'].' '. $this->http_codes[$info['http_code']]);
                return false;
            }
            return $recodedContent;
        } else {
            return false;
        }
    }

    /*
     * Execute a specific remote procedure and return the response string
     *
     * @param string $command  the remote procedure
     */
    protected function executeJsonRpc ($command) {
        // checks and matches the command
        $pattern = '/^([a-z]+)\:([a-zA-Z]+)(\:(.*))?$/';
        preg_match($pattern, $command, $matches);
        if (count($matches) == 0 ) {
            $this->echoError('The command "'.$command.'" is not a valid owcli command.');
            return false;
        } else {
            $serverAction = $matches[1];
            $rpcMethod = $matches[2];
            $rpcParameter = $matches[4];
        }
        $this->echoDebug("starting jsonrpc: $serverAction:$rpcMethod");
        $this->currentCommandId++;

        // create a new cURL resource
        $rpc = curl_init();
        curl_setopt ($rpc, CURLOPT_USERAGENT, self::NAME . ' ' . self::VERSION);
        curl_setopt ($rpc, CURLOPT_RETURNTRANSFER, true);
        curl_setopt ($rpc, CURLOPT_CONNECTTIMEOUT, 30);

        // define jsonrpc server URL
        $serverUrl = $this->wikiConfig['baseuri'] . '/jsonrpc/'.$serverAction;
        curl_setopt ($rpc, CURLOPT_URL, $serverUrl);

        // retrieve Service Mapping Description (SMD) (if not already done)
        if (!$this->smd[$serverAction]) {
            $this->smd[$serverAction] = $this->getSMD($serverUrl);
        }
        $serversmd = $this->smd[$serverAction];
        if ($serversmd->services->$rpcMethod) {
            $methodsmb = $serversmd->services->$rpcMethod;
        } else {
            $this->echoError('The command "'.$rpcMethod.'" has no valid Service Mapping Description from the server.');
            return false;
        }
        $parameters = $methodsmb->parameters;
        #var_dump($parameters);

        // split parameters by explode to use it in procedure parameters
        if ($rpcParameter) {
            $rpcParameterArray = explode(':', $rpcParameter);
            #var_dump($rpcParameterArray);
        }

        // define the post data; based on the SMD
        $postdata['method'] = $rpcMethod;
        foreach ($parameters as $parameter) {
            $key = $parameter->name;
            unset ($value);
            switch ($key) {
                case 'modelIri':
                    $value = $this->selectedModel;
                    break;

                case 'inputModel':
                    if ($this->inputModel) {
                        $value = $this->inputModel;
                    } else {
                        $this->echoError("The command '$command' needs a model input.");
                        return false;
                    }
                    break;

                default:
                    break;
            }
            
            if ($value) {
                $postdata['params'][$key] = $value;
                $this->echoDebug("Use internal value for parameter '$key'");
            } elseif (count($rpcParameterArray) > 0) {
                // take the first array element
                $value = reset($rpcParameterArray);
                // unset this element from the parameter array
                $valueKeys = array_keys ($rpcParameterArray, $value);
                unset($rpcParameterArray[$valueKeys[0]]);
                // set value as parameter
                $postdata['params'][$key] = $value;
                $this->echoDebug("Use given value '$value' for parameter '$key'");
            } elseif ($parameter->default) {
                $value = $parameter->default;
                $postdata['params'][$key] = $value;
                $this->echoDebug("Use default value '$value' for parameter '$key'");
            } else {
                $this->echoError("The command '$serverAction:$rpcMethod' needs a value for parameter '$key' but no more values given.");
                return false;
            }
        }

        $postdata['id'] = $this->currentCommandId;
        $postdata = json_encode($postdata);

        // this is too much information so only if debug AND raw is turned on
        if ($this->args->isDefined('raw')) {
            $this->echoDebug('postdata: ' . $postdata);
        }

        curl_setopt ($rpc, CURLOPT_POST, true);
        curl_setopt ($rpc, CURLOPT_POSTFIELDS, $postdata);

        // add authentification header if there are auth credentials configured
        if ( $this->wikiConfig['user'] && $this->wikiConfig['password'] ) {
            $headers = array(
                "Authorization: Basic " .
                    base64_encode($this->wikiConfig['user'].':'.$this->wikiConfig['password'])
            );
            curl_setopt ($rpc, CURLOPT_HTTPHEADER, $headers);
            curl_setopt ($rpc, CURLOPT_HTTPAUTH, CURLAUTH_ANY);            
        }

        // catch URL and work on response
        $response = curl_exec($rpc);

        // Check if any error occured in curl
        if(curl_errno($rpc)) {
            $this->echoError('Error: '.curl_error($rpc));
            die();
        } else {
            $info = curl_getinfo($rpc);
            $this->echoDebug('Took ' . $info['total_time'] . ' seconds to send a request to ' . $info['url']);
            curl_close($rpc);

            if ($info['http_code'] != 200) {
                $this->echoError('Error on executeJsonRpc: '. $info['http_code'].' '. $this->http_codes[$info['http_code']]);
                die();
            }
            

            $decodedResponse = json_decode($response, true);

            if (!$response) {
                $this->echoError("Error: no response on serveruri $serverUrl");
                die();
            } elseif (!is_array($decodedResponse)) {
                $this->echoError('The server response was no valid json:');
                $response = preg_replace('/\<style.+style\>/s', '', $response);
                $response = preg_replace('/\<head.+head\>/s', '', $response);
                $this->echoError(trim(strip_tags($response)));
                die();
            } else {
                return $response;
            }
        }
    }

    /**
     * Write STDERR-String
     */
    protected function echoError ($string) {
        fwrite(STDERR, $string ."\n");
    }

    /**
     * Write STDERR-String if Debug-Mode on
     */
    protected function echoDebug ($string) {
	if ($this->args->isDefined('debug')) {
            fwrite(STDERR, $string . "\n");
        }
    }

    /*
     * Load required PEAR Packages
     */
    protected function checkPearPackages() {
	foreach ($this->pearPackages as $package) {
            if (!@include_once $package ) {
                $packageName = str_replace('.php', '', $package);
                $packageName = str_replace('/', '_', $packageName);
                $this->echoError("PEAR package $package needed!");
                $this->echoError("Mostly, you can install it with 'sudo pear install $packageName' ...");
                die();
            }
        }
    }

    /*
     * Check for addionally tools and APIs
     */
    protected function checkTools() {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->echoError('¡Ay, caramba! - owcli on Windows! This is not fully supported yet.');
            #$this->echoError('The owcli needs rapper, the Raptor RDF parsing and serializing utility.');
            #$this->echoError('Please install it from http://download.librdf.org/binaries/win32/');
            #$this->echoError('The configure "rapper = path/to/rapper.exe" in your owcli.ini');
        } else {
            $rapperbin = `which rapper`;
            if (!$rapperbin) {
                $this->echoError('The owcli needs rapper, the Raptor RDF parsing and serializing utility.');
                $this->echoError('On a debian based system, try "sudo apt-get install raptor-utils"');
                die();
            }
        }

        if (!function_exists('curl_version')) {
            $this->echoError('The owcli needs php support for libcurl!');
            $this->echoError('On a debian based system, try "sudo apt-get install php5-curl"');
            die();
        }
    }

    /*
     * Generate command line parameter array for Console_Getargs
     */
    protected function checkCommandLineArguments() {

        // Some default parameter values can be overwritten by variables
        $defaultModel = getenv('OWMODEL') ? getenv('OWMODEL') : 'http://localhost/OntoWiki/Config/';
        $defaultWiki = getenv('OWWIKI') ? getenv('OWWIKI') : 'default';
        $defaultConfig = getenv('OWCONFIG') ? getenv("OWCONFIG") : getenv('HOME').'/.owcli';

        $this->argConfig = array(
            'execute' => array(
                'short' => 'e',
                'max' => -1,
                'desc' => 'Execute one or more commands'
            ),

            'wiki' => array(
                'short' => 'w',
                'max' => 1,
                'default' => $defaultWiki,
                'desc' => 'Set the wiki which should be used'
            ),

            'model' => array(
                'short' => 'm',
                'max' => 1,
                'default' => $defaultModel,
                'desc' => 'Set model which should be used'
            ),

            'input' => array(
                'short' => 'i',
                'max' => -1,
                'desc' => 'Set input model file (- for STDIN)'
            ),

            'inputOptions' => array(
                'min' => 1,
                'max' => 1,
                'default' => '-i rdfxml',
                'desc' => 'rapper cmd input options'
            ),

/* not really needed
            'output' => array(
                'short' => 'o',
                'min' => 1,
                'max' => 1,
                'default' => "-",
                'desc' => 'Set output model file (- for STDOUT)'
            ),
*/
            
            'config' => array(
                'short' => 'c',
                'max' => 1,
                'default' => $defaultConfig,
                'desc' => 'Set config file'
            ),

            'listModels' => array(
                'short' => 'l',
                'max' => 0,
                'desc' => 'This is a shortcut for -e store:listModels'
            ),

            'listProcedures' => array(
                'short' => 'p',
                'max' => 0,
                'desc' => 'This is a shortcut for -e meta:listAllProcedures'
            ),

            'debug' => array(
                'short' => 'd',
                'max' => 0,
                'desc' => 'Output some debug infos'
            ),

            'quiet' => array(
                'short' => 'q',
                'max' => 0,
                'desc' => 'Do not output info messages'
            ),

            'raw' => array(
                'short' => 'r',
                'max' => 0,
                'desc' => 'Outputs raw json results'
            ),

            'help' => array(
                'short' => 'h',
                'max' => 0,
                'desc' => 'Show this screen'
            ),
        );

	$header = self::NAME . ' ' . self::VERSION . PHP_EOL .
		'Usage: '.basename($_SERVER['SCRIPT_NAME']).' [options]' . PHP_EOL . PHP_EOL;
	$footer = PHP_EOL . 'Note: Some commands are limited to the php.ini value memory_limit ...';

	$this->args =& Console_Getargs::factory($this->argConfig);

	if (PEAR::isError($this->args)) {
            if ($this->args->getCode() === CONSOLE_GETARGS_ERROR_USER) {
                $this->echoError ($this->args->getMessage());
                $this->echoError (PHP_EOL . 'Try "'.basename($_SERVER['SCRIPT_NAME']).' --help" for more information');
            }
            elseif ($this->args->getCode() === CONSOLE_GETARGS_HELP) {
                $this->echoError (Console_Getargs::getHelp($this->argConfig, $header, $footer));
            }
            die();
	} elseif (count($this->args->args) == 0) {
            $this->echoError (self::NAME ." ". self::VERSION);
            $this->echoError ('Try "'.basename($_SERVER['SCRIPT_NAME']).' --help" for more information');
            exit();
	}

        // create command list, use shortcut, if available
        if ($this->args->isDefined('listModels')) {
            $this->commandList[] = 'store:listModels';
        }
        // create command list, use shortcut, if available
        if ($this->args->isDefined('listProcedures')) {
            $this->commandList[] = 'meta:listAllProcedures';
        }
        // append -e commands to the end to the command queue
        if ( count((array) $this->args->getValue('execute')) > 0 ) {
            foreach ((array) $this->args->getValue('execute') as $command) {
                $this->commandList[] = $command;
            }
        }
        if ( count($this->commandList) == 0 ) {
            $this->echoError('I don\'t know what to do. Please try -l or -e ... ');
            die();
        }
    }

    /**
     * Load and check config file
     */
    protected function checkConfig() {
        $file = $this->args->getValue('config');
	$config = @parse_ini_file($file, TRUE);

	if (!isset($config)) {
            $this->echoError ('Can\'t open config file $file');
            die();
	}

	$wiki = $this->args->getValue('wiki');
	if (!isset($config[$wiki])) {
            $this->echoError ('Wiki instance '.$wiki.' not configured in configfile '.$file);
            die();
	} elseif ( !isset($config[$wiki]['baseuri']) ) {
            $this->echoError ('Wiki instance '.$wiki.' has no baseuri in configfile '.$file);
            die();
        }

        $this->wikiConfig = $config[$wiki];

	$this->config = $config;

        #$this->wiki = $this->config[]
	$this->echoDebug ('Config file loaded and ok');

    }

}

// start the programm
$owcli = new OntowikiCommandLineInterface();
