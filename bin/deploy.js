/**
 * Deploy the local site to -d or -q
 * Test and verify deploy with `npm run deploy`
 * Deploy with `npm run deploy -- --no-dryrun`
 * Test and verify on target stage, e.g. https://puttputtplanet-q.com
 */
let rsyncFlags = "zrptcv";
let debug = false;
let configuration = {};
const Rsync = require("rsync");
const Chalk = require("chalk");
const fs = require("fs");
require('shelljs/global');
const defaultConfigurationFilePath = "bin/deploy-config.json";

/**
 * Set defaults for things that we may not receive from the configuration file
 * or the command line. There are certain parameters that we cannot default and
 * must be provided.
 */
const configurationDefault = {
    site: "puttputtplanet",
    targetstage: "-q",
    isDryRun: false,
    destinationHost: "",
    destinationUser: "",
    destinationPassword: "",
    destinationPath: "/var/www/vhosts/puttputtplanet-q",
    excludeFiles: "./bin/exclude-puttputtplanet-files.txt",
    sourcePath: "./",
    sshKeyFile: "",
    debug: false,
    logFile: "",
    configurationFile: defaultConfigurationFilePath
}

/**
 * Load the required configuration information from a JSON file.
 * This file contains sensitive information and must be secure
 * (don't put it in version control, and keep access rights restricted to 600.)
 * @param {string} configurationFilePath path to a configuration file.
 * @returns {object} The configuration data or an empty object if no data is available.
 */
function loadConfigurationData(configurationFilePath) {
    if (fs.existsSync(configurationFilePath)) {
        let rawData = fs.readFileSync(configurationFilePath);
        if (rawData != null) {
            return JSON.parse(rawData) || {};  
        }
    }
    return {};
}

/**
 * Merge the configuration information with the default values. Anything found
 * in the loaded configuration file will override a default.
 * @param {object} configurationDefault Default configuration information.
 * @return {object} Configuration information.
 */
function mergeConfigurationData(configurationDefault) {
    const args = getArgs();
    debug = args.verbose;
    let configuration;
    let configurationFilePath = args.config || defaultConfigurationFilePath;
    if (configurationFilePath.length > 0) {
        configuration = loadConfigurationData(configurationFilePath);
        if (Object.keys(configuration).length === 0) {
            immediateLog("Configuration file " + configurationFilePath + " does not exist or is not a valid format.");
        } else {
            immediateLog("Loading configuration from " + configurationFilePath, false);
        }
    }
    for (let property in configurationDefault) {
        if (property != "configurationFile" && configurationDefault.hasOwnProperty(property) && ! configuration.hasOwnProperty(property)) {
            configuration[property] = configurationDefault[property];
        }
    }
    mergeArgs(args, configuration);
    if (configuration.hasOwnProperty("debug")) {
        debug = configuration.debug;
    }
    return configuration;
}

/**
 * Overwrite any configuration options with values provided on the command line.
 * Command line has precedence over config file.
 * @param {object} args Command line arguments.
 * @param {object} configuration Default configuration information.
 * @return {object} Configuration information.
 */
function mergeArgs(args, configuration) {
    if (args.destination) {
        configuration.destinationPath = args.destination;
    }
    if (args.site) {
        configuration.site = args.site;
    }
    if (args.host) {
        configuration.destinationHost = args.host;
    }
    if (args.key) {
        configuration.sshKeyFile = args.key;
    }
    if (args.log) {
        configuration.logFile = args.log;
    }
    if (args.source) {
        configuration.sourcePath = args.source;
    }
    if (args.targetstage) {
        configuration.targetstage = args.targetstage;
    }
    if (args.user) {
        configuration.destinationUser = args.user;
    }
    if (args.exclude) {
        configuration.excludeFiles = args.exclude;
    }
    if (args.hasOwnProperty('verbose') && args.verbose) {
        configuration.debug = args.verbose;
    }
    if (args.hasOwnProperty('dryrun')) {
        configuration.isDryRun = args.dryrun;
    }
    console.log(Chalk.blue("isDryRun is " + (configuration.isDryRun?"true":"false")));
    return configuration;
}

/**
 * Overwrite any configuration options with values provided on the command line.
 * @return {object} Args object.
 */
function getArgs() {
    const args = require("yargs")
    .options({
        "config": {
            alias: "c",
            type: "string",
            describe: "path to config file",
            demandOption: false,
            default: defaultConfigurationFilePath
        },
        "destination": {
            alias: "d",
            type: "string",
            describe: "destination root path to copy to on host",
            demandOption: false
        },
        "site": {
            alias: "e",
            type: "string",
            describe: "set which site to deploy",
            demandOption: false
        },
        "host": {
            alias: "h",
            type: "string",
            describe: "host domain to copy to",
            demandOption: false
        },
        "key": {
            alias: "k",
            type: "string",
            describe: "path to ssh key file (pem format)",
            demandOption: false
        },
        "log": {
            alias: "l",
            type: "string",
            describe: "path to log file",
            demandOption: false
        },
        "source": {
            alias: "s",
            type: "string",
            describe: "set the source file root folder",
            demandOption: false
        },
        "targetstage": {
            alias: "t",
            type: "string",
            describe: "set the server stage to deploy to",
            demandOption: false
        },
        "user": {
            alias: "u",
            type: "string",
            describe: "user on destination to login as (using key file)",
            demandOption: false
        },
        "verbose": {
            alias: "v",
            type: "boolean",
            describe: "turn on debugging",
            demandOption: false,
            default: false
        },
        "exclude": {
            alias: "x",
            type: "string",
            describe: "path to exclude file list (text file)",
            demandOption: false
        },
        "dryrun": {
            alias: "y",
            type: "boolean",
            describe: "perform dry run (no actual sync)",
            demandOption: false,
            default: true
        },
    })
    .alias("?", "help")
    .help()
    .argv;
    return args;
}

/**
 * Write a message to a log file.
 * @param {string} message The message to post in the log.
 */
function writeToLogFile(message) {
    if (configuration && configuration.logFile) {
        try {
            fs.appendFileSync(configuration.logFile, message + "\r\n");
        } catch (err) {
            console.log(Chalk.red("Error writing to " + configuration.logFile + ": " + err));
        }
    }
}

/**
 * Show an error message in the log and on the console but only if debugging is enabled.
 * @param {string} message A message to display.
 */
function errorLog(message) {
    if (debug) {
        console.log(Chalk.red(message));
        writeToLogFile(message);
    }
}

/**
 * Show an information message in the log and on the console but only if debugging is enabled.
 * @param {string} message A message to display.
 */
function debugLog(message) {
    if (debug) {
        console.log(Chalk.green(message));
        writeToLogFile(message);
    }
}

/**
 * Show a message in the log and on the console immediately.
 * @param {string} message A message to display.
 */
function immediateLog(message, error = true) {
    if (error) {
        console.log(Chalk.red(message));
    } else {
        console.log(Chalk.blue(message));
    }
    writeToLogFile(message);
}

function updateBuildInfoFile() {
    var path = require('path'),
        buildFileName = 'build-info.json',
        buildFolder = './public',
        user = env['USER'],
        package_name = env['npm_package_name'],
        version = env['npm_package_version'],
        buildFile = path.join(buildFolder, buildFileName),
        currentDateTime = (new Date()).toISOString(),
        buildInfo = {
            packageName: package_name,
            version: version,
            publish_date: currentDateTime,
            user: user
        };
    echo(JSON.stringify(buildInfo)).to(buildFile);
}

function deploy(configuration) {
    const site = configuration.site;
    const isDryRun = configuration.isDryRun;
    const sourcePath = configuration.sourcePath;
    const excludeFiles = configuration.excludeFiles;
    const dryRunFlag = "n";
    let sshCommand = "ssh";
    let destinationPath;
    let logMessage = "Deploying " + site + " to " + configuration.targetstage + " on " + (new Date).toISOString();

    if (configuration.destinationUser.length > 0 && configuration.destinationHost.length > 0) {
        destinationPath = configuration.destinationUser + "@" + configuration.destinationHost + ":" + configuration.destinationPath;
    } else {
        destinationPath = configuration.destinationPath;
    }
    if (configuration.logFile && fs.existsSync(configuration.logFile)) {
        fs.unlinkSync(configuration.logFile);
    }
    if (isDryRun) {
        rsyncFlags += dryRunFlag;
        logMessage += " -- This is a DRY RUN - no files will be copied."
    } else {
        updateBuildInfoFile();
    }
    if (configuration.sshKeyFile) {
        sshCommand += " -i " + configuration.sshKeyFile;
    }
    immediateLog(logMessage, false);
    immediateLog("Syncing " + site + " " + sourcePath + " with target stage " + configuration.targetstage + " " + configuration.destinationPath, false);
    debugLog("sourcePath " + sourcePath);
    debugLog("destinationPath " + destinationPath);
    debugLog("sshCommand " + sshCommand);
    debugLog("rsync flags " + rsyncFlags);
    debugLog("excludeFiles " + excludeFiles);
    debugLog("log to " + configuration.logFile);
    debugLog("debug " + configuration.debug);

    let rsync = new Rsync()
        .shell(sshCommand)
        .flags(rsyncFlags)
        .delete()
        .set("exclude-from", excludeFiles)
        .source(sourcePath)
        .destination(destinationPath);

    if (isDryRun) {
        immediateLog("Review deploy dry run " + site + " to " + destinationPath, false);
    } else {
        immediateLog("Deploy " + site + " to " + destinationPath, false);
    }

    rsync.execute(function(error, exitCode, cmd) {
        const timeNow = (new Date).toISOString();
        if (error) {
            immediateLog("Site deploy fails for " + site + " " + error.toString() + " at " + timeNow);
        } else if (isDryRun) {
            immediateLog("Site dry run for " + site + " complete at "  + timeNow);
        } else {
            immediateLog("Site deploy for " + site + " complete at "  + timeNow);
        }
    }, function (output) {
        // stdout
        debugLog(output);
    }, function (output) {
        // stderr
        errorLog(output);
    });
}

configuration = mergeConfigurationData(configurationDefault);
deploy(configuration);
