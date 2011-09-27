# owcli - the OntoWiki Command Line Interface

The OntoWiki Command Line Client is a script-able and pipe-able PHP application for the Unix command line to help you to manage your OntoWiki knowledge bases.

## Installation

Install a command line php5 and pear, on debian/ubuntu:

    sudo apt-get install php5-cli php5-curl php-pear raptor-utils

Install required pear packages, on debian/ubuntu: 

    sudo pear install Console_Getargs Config Console_Table

Download the [owcli download package](https://github.com/AKSW/owcli/tarball/master).

Unpack it and run 

    make -B install

in the unpacked directory. After starting a new shell (to rehash the PATH variable), you can type 

    owcli

into you command line and there should be an output like this:

    > owcli
    The OntoWiki CLI 0.3
    Try "owcli --help" for more information

and you can gather general help by typing

    > owcli --help
    The OntoWiki CLI 0.5
    Usage: owcli [options]

    -e --execute (optional)values  Execute one or more commands
    -w --wiki=<value>              Set the wiki which should be used (default)
    -m --model=<value>             Set model which should be used
                                   (http://localhost/OntoWiki/Config/)
    -i --input (optional)values    Set input model file (- for STDIN)
       --inputOptions=<value>      rapper cmd input options (-i rdfxml)
    -c --config=<value>            Set config file (/home/seebi/.owcli)
    -l --listModels                This is a shortcut for -e store:listModels
    -p --listProcedures            This is a shortcut for -e meta:listAllProcedures
    -d --debug                     Output some debug infos
    -q --quiet                     Do not output info messages
    -r --raw                       Outputs raw json results
    -z --zsh=<value>               zsh friendly output (do not use manually)
    -h --help                      Show this screen

    Note: Some commands are limited to the php.ini value memory_limit ...


## Configuration

You have to create a file `.owcli` in your unix-home directory which can be used by owcli.
This file configures your access parameters to your OntoWiki installations.
Here is an example:

    [default] 
    baseuri = "http://localhost/ow/trunk/"
    user = "Admin"
    password = "mypass"
    defaultmodel = "http://example.org/"

Note: This config setups on wiki instance which is on you local host and which will be access via the Admin user.
The default model is used every time you do not give an explicit model to the command line.

You can have more than one sections (like `[default]`) but they need a unique name.
To switch between the configured wiki installations, use the `-w ` parameter.

## Usage

owcli is an RPC client which follows the [JSON RPC protocol specification](http://json-rpc.org/wiki/specification).
This means, owcli runs a remote procedure on your OntoWiki instance which is an JSON/RPC server.

If you have enabled the `jsonrpc` component extension on your Ontowiki instance (which is enabled by default) you can access different jsonrpc servers on it.
Every server is used for a specific category of tasks and on every server there are multiple procedures which can be started and which sometimes need more parameter.

The standard form of executing a remote procedure (with `-e`) is

    [server]:[procedure]:[p1],[p2],...

Note: most of the command do not need parameters.
If you forget a parameter, owcli will inform you about that:

    > owcli -m http://example.org -e model:addPrefix   
    The command 'model:addPrefix' needs a value for parameter 'prefix' but no more values given.
    Something went wrong, response was not json encoded (turn debug on to see more)

Currently, these servers are enabled and you can verify it on your installation by typing:

    > owcli -e meta:listServer
    +-------+--------------------------------------------------+
    | name  | description                                      |
    +-------+--------------------------------------------------+
    | meta  | methods to query the json service itself         |
    | store | methods to manipulate and query the store        |
    | model | methods to manipulate and query a specific model |
    +-------+--------------------------------------------------+

Note: This command executes the procedure `listServer` on the `meta`-server which has `methods to query the json service itself`.

To list all methods in the `meta`-category, you can use the `listProcedure` command in the same category.
So if you wanna know, which procedure are available in the `model`-category you can type:

    > owcli -e meta:listProcedures:model
    +--------------+--------------------------------------------+
    | name         | description                                |
    +--------------+--------------------------------------------+
    | model:export | exports a model as rdf/xml                 |
    | model:sparql | performs a sparql query on the model       |
    | model:count  | counts the number of statements of a model |
    | model:add    | add all input statements to the model      |
    | model:create | create a new knowledge base                |
    | model:drop   | drop an existing knowledge base            |
    +--------------+--------------------------------------------+

Note: This command executes the procedure `listProcedures` on the `meta`-server with one parameter which has the value `model` (which stands for the category to query).

BTW: To list all procedures in all categories, you can run `meta:listAllProcedures` which has also a hard-wired shortcut `-p`.

    > owcli -p
    +------------------------+-------------------------------------------------------------+
    | name                   | description                                                 |
    +------------------------+-------------------------------------------------------------+
    | meta:listServer        | lists all jsonrpc server from the wiki instance             |
    | meta:listProcedures    | lists all remote procedures from a specific jsonrpc server  |
    | meta:listAllProcedures | lists all remote procedures from ALL jsonrpc server         |
    | store:listModels       | list modelIris which are readable with the current identity |
    | store:getBackendName   | return the name of the backend (e.g. Zend or Virtuoso)      |
    | store:sparql           | performs a sparql query on the store                        |
    | store:getIdentity      | returns the label of the current identity                   |
    | model:export           | exports a model as rdf/xml                                  |
    | model:sparql           | performs a sparql query on the model                        |
    | model:count            | counts the number of statements of a model                  |
    | model:add              | add all input statements to the model                       |
    | model:create           | create a new knowledge base                                 |
    | model:drop             | drop an existing knowledge base                             |
    +------------------------+-------------------------------------------------------------+

Another hard-wired shortcut is `-l` which stands for `-e store:listModels` and output the URIs of all readable models (readable means readable for the configured account).

    > owcli -l
    http://localhost/OntoWiki/Config/
    http://ns.ontowiki.net/SysOnt/
    http://ns.ontowiki.net/SysBase/

## Examples

Create a new example Model and add tripels from a file after that:

    > owcli -m http://example.org -e model:create model:add -i model.rdf

Note: The order of the procedures is important ...

Since from now on, we would always type `-m http://example.org` to access the example model, we can configure it in the `.owcli` file as default model or we can modify the environment variable `OWMODEL` to set the default model for this session.

    > export OWMODEL="http://example.org"

Note: Other environment variables are `OWWIKI` (instead of `-w wikiname`) and `OWCONFIG` (instead of `-c configfile`)

Catch the FOAF schema from the web and add it to the example model too:

    wget -q -O - http://xmlns.com/foaf/spec/index.rdf | owcli -e model:add -i -

Note: owcli can read content from the stdin too, so you can use it in a pipe ...

Count the example model:

    > owcli -e model:count
    14647

Export the example model to a file:

    > owcli -e model:export >myNewModel.rdf

Export the example model, put it to [cwm](http://www.w3.org/2000/10/swap/doc/cwm) and add the inferred statements from the rule file:

    > owcli -e model:export | cwm --rdf --n3 --filter=Rules.n3 --rdf | owcli -e model:add -i -

Remove the example model from the store:

    > owcli -e model:drop

