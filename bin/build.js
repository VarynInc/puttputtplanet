/**
 * Build the website. This module supports the following tasks:
 *   - optimize images
 *   - minify and combine ./public/common/*.js
 * Run this from the command line: npm run build -- --config ./bin/build-config.json --no-dryrun --no-verbose
 * The built files end up in the destination folder.
 * @author: jf 14-Dec-2019
 */
import os from "os";
import asyncp from "async";
import path from "path";
import { glob } from "glob";
import chalk from "chalk";
import prettyBytes from "pretty-bytes";
import fsExtra from "fs-extra";
import { minify } from "terser";
import args from "yargs";
import CleanCSS from "clean-css";
import shell from "shelljs";
import { execSync } from "node:child_process";
import jspackage from "../package.json" with { type: "json" };
const version = jspackage.version;

// Local module variables
const numberOfCPUs = os.cpus().length;
let compressionStats = {
    totalFiles: 0,
    totalBytesConsidered: 0,
    totalBytesCompressed: 0,
    startTime: new Date(),
    endTime: null
};

// Configurable parameters:
let configuration = {
    dryrun: true,
    isLoggingInfo: true,
    isLoggingError: true,
    isCompressJavaScript: true,
    isMangleJavaScript: true,
    buildFileName: 'build-info.json',
    buildFolder: './public',
    logfile: null,
    verbose: true,
    optimizeImages: true,
    packageName: "puttputtplanet.min.js",
    configurationFile: null,
    jsSource: "./public/js",
    jsDestination: "./public/js",
    exclude: null,
    unoptimizedFileSpec: "{eot,ttf,woff,woff2,svg,mp3,ogg,wav,json,jpg,jpeg,png,gif}",
    pageManifest: {
    },
    libManifest: [
        "bootstrap.bundle.min.js",
        "enginesis.js",
        "commonUtilities.js"
    ],
    filesToCopy: [ // a list of files to copy to destination without modification
    ],
    jsFilesToIgnore: [ // a list of files in the js folder to skip
    ],
    libsToCopy: [
        "bootstrap.bundle.min.js"
    ],
    libsToCombine: [
        "commonUtilities.js",
        "enginesis.js"
    ],
    combinedLibFileName: "enginesis.min.js"
};

/**
 * Helper function to control logging informational progress messages.
 * @param {string} message
 */
function logInfo(message) {
    if (configuration.isLoggingInfo) {
        console.log(chalk.green(message));
    }
}
/**
 * Helper function to control logging errors.
 * @param {string} message
 */
function logError(message) {
    if (configuration.isLoggingError) {
        console.warn(chalk.red("ᚎ " + message));
    }
}

/**
 * Capture any command line arguments and update configuration variables.
 * @return {object} Args object.
 */
 function getArgs() {
    return args(process.argv)
    .options({
        "config": {
            alias: "c",
            type: "string",
            describe: "path to configuration file",
            demandOption: false,
            default: "./bin/build-config.json"
        },
        "jsDestination": {
            alias: "d",
            type: "string",
            describe: "copy compressed .js files to specified path",
            demandOption: false
        },
        "logfile": {
            alias: "l",
            type: "string",
            describe: "path to log file",
            demandOption: false
        },
        "jsSource": {
            alias: "s",
            type: "string",
            describe: "set the source file root folder for .js files",
            demandOption: false
        },
        "verbose": {
            alias: "v",
            type: "boolean",
            describe: "turn on extra debugging",
            demandOption: false,
            default: true
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
            describe: "perform dry run (no actual copy)",
            demandOption: false
        },
    })
    .alias("?", "help")
    .help()
    .argv;
}

/**
 * Capture any command line arguments and update configuration variables.
 */
function getCommandLineArguments() {
    const args = getArgs();
    configuration.optimizeImages = args.optimizeImages;
    configuration.isLoggingInfo = args.verbose;
    configuration.isLoggingError = true;
    return args;
}

/**
 * Load the configuration information from a JSON file.
 * @param {string} configurationFilePath path to a configuration file.
 * @returns {Promise} Resolves with the configuration data or an empty object if no data is available.
 */
function loadConfigurationData(configurationFilePath) {
    return new Promise(function(resolve) {
        fsExtra.readJSON(configurationFilePath)
        .then(function(configuration) {
            resolve(configuration);
        })
        .catch(function(exception) {
            logError(`Configuration file ${configurationFilePath} error when reading: ${exception.toString()}`);
            resolve({});
        });
    });
}

/**
 * Merge command line options together with the configuration object such that options from
 * the command line take priority.
 * @param {object} configurationProperties A set of command line parameters we got from the command line.
 */
function matchProperties(configurationProperties) {
    const configurableProperties = ["jsDestination", "dryrun", "exclude", "logfile", "jsSource", "verbose"];
    configurableProperties.forEach(function(property) {
        if (configurationProperties.hasOwnProperty(property)) {
            logInfo(">>> Setting ." + property + " to " + configurationProperties[property]);
            configuration[property] = configurationProperties[property];
        };
    });
}

/**
 * Merge configuration options from command line, configuration file, with default configuration. Command line
 * overrides configuration file which overrides defaults.
 */
function updateConfiguration() {
    const cliArgs = getCommandLineArguments();

    return new Promise(function(resolve) {
        if (cliArgs.config != null) {
            logInfo("ᙘ got config");
            fsExtra.pathExists(cliArgs.config)
            .then(function(configurationFileExists) {
                if (configurationFileExists) {
                    logInfo(`Configuration file ${cliArgs.config} overriding any matching default options.`);
                    loadConfigurationData(cliArgs.config)
                    .then(function(configurationData) {
                        // iterate over configuration and replace matching properties.
                        matchProperties(configurationData);
                        // override configuration with anything on CLI
                        logInfo(`CLI overriding any matching options.`);
                        matchProperties(cliArgs);
                        resolve();
                    });
                } else {
                    logError(`Configuration file ${cliArgs.config} does not exist, continuing with the default configuration.`);
                    // override configuration with anything on CLI
                    matchProperties(cliArgs);
                    resolve();
                }
            });
        } else {
            logInfo("ᙘ no config?");
        }
    });
}

/**
 * Optimize all css files found in sourcePath and copy the optimized version to destinationPath.
 * @param {string} sourcePath path to the root folder under which to find css files.
 * @param {string} destinationPath path where to copy optimized files.
 * @returns {Promise} A promise that resolves when the CSS files are optimized and copied to the destination folder, or rejects if there is an error.
 */
async function optimizeCSS(sourcePath, destinationPath) {
    const globSpec = path.join(sourcePath, "/**/") + "*.css";
    const sourcePathLength = sourcePath.length;
    const cleanCSSOptions = {
        returnPromise: true
    };
    let totalBytesConsidered = 0;
    let totalBytesCopied = 0;
    let totalFilesCopied = 0;

    return new Promise(async function (resolve, reject) {
        logInfo(chalk.green("ᗘ Starting CSS optimization for " + globSpec));
        const files = await glob(globSpec);
        asyncp.eachLimit(files, numberOfCPUs, function (file, callback) {
            const destinationFile = path.join(destinationPath, file.substr(sourcePathLength));
            fsExtra.stat(file, function (error, fileStat) {
                if (error) {
                    logError(file + " -- fstat error " + error.toString());
                    callback(error);
                    return;
                }
                const originalFileSize = fileStat.size;
                const newPath = path.dirname(destinationFile);
                const fileContents = fsExtra.readFileSync(file, {encoding: "utf8", flag: "r"});
                if (fileContents != null && fileContents.length > 0) {
                    new CleanCSS(cleanCSSOptions)
                        .minify(fileContents)
                        .then(function (cleanCSSResult) {
                            if ( ! fsExtra.existsSync(newPath)) {
                                fsExtra.mkdirSync(newPath);
                            }
                            fsExtra.writeFileSync(destinationFile, cleanCSSResult.styles);
                            totalFilesCopied += 1;
                            totalBytesConsidered += originalFileSize;
                            totalBytesCopied += cleanCSSResult.stats.minifiedSize;
                            const bytesSaved = totalBytesConsidered - totalBytesCopied;
                            let statusMessage;
                            if (bytesSaved > 9) {
                                statusMessage = chalk.green("saved " + prettyBytes(bytesSaved) + " (" + (cleanCSSResult.stats.efficiency * 100).toFixed() + "%)");
                            } else {
                                statusMessage = chalk.yellow("was optimized");
                            }
                            logInfo(chalk.green("ᗘ ") + chalk.gray(file + " -- copy to " + destinationFile) + " -- " + statusMessage);
                            callback(null);
                        })
                        .catch(function (error) {
                            logError(file + " -- CleanCSS error " + error.toString());
                            callback(null);
                        });
                } else {
                    logError("some wrong with " + file);
                    callback(null);
                }
            });
        }, function (error) {
            compressionStats.totalFiles += totalFilesCopied;
            compressionStats.totalBytesConsidered += totalBytesConsidered;
            compressionStats.totalBytesCompressed += totalBytesCopied;
            if (error) {
                reject(new Error("optimizeCSS process error " + error.toString()));
            } else {
                const totalSaved = totalBytesConsidered - totalBytesCopied;
                const percentSaved = totalBytesConsidered == 0 ? 0 : ((totalSaved / totalBytesConsidered) * 100).toFixed() + "%";
                logInfo(chalk.green("ᙘ Completed CSS optimization for " + totalFilesCopied + " files, saved " + prettyBytes(totalSaved) + " " + percentSaved));
                resolve();
            }
        });
    });
}

/**
 * Optimize all js files found in configuration.pageManifest and copy the optimized
 * version to configuration.destinationFolder.
 *
 * @param {object} varynConfiguration Configuration properties.
 */
function optimizeJS(varynConfiguration) {
    const globSpec = path.join(varynConfiguration.jsSource, "*.js");
    const fileGroups = varynConfiguration.pageManifest;
    let sourceFolder = varynConfiguration.jsSource;
    let destinationFolder = varynConfiguration.jsDestination;
    let totalBytesConsidered = 0;
    let totalBytesCopied = 0;
    let terserOptions = {
        warnings: true,
        toplevel: false
    };
    if ( ! varynConfiguration.isCompressJavaScript) {
        terserOptions.compress = false;
    }
    if ( ! varynConfiguration.isMangleJavaScript) {
        terserOptions.mangle = false;
    }

    function prepareJSFile(file, terserCode) {
        const fileName = path.basename(file);
        const filePath = path.join(sourceFolder, file);
        logInfo("ᗘ JS compress source " + filePath);

        if (configuration.jsFilesToIgnore.indexOf(fileName) < 0) {
            const fileContents = fsExtra.readFileSync(filePath, { encoding: "utf8", flag: "r" });
            if (fileContents != null && fileContents.length > 0) {
                compressionStats.totalFiles += 1;
                let fileSize = Buffer.byteLength(fileContents);
                totalBytesConsidered += fileSize;
                compressionStats.totalBytesConsidered += fileSize;
                terserCode[fileName] = fileContents;
            } else {
                logError("prepareJSFile Error reading file " + filePath);
            }
        }
    }

    async function completeJSCompression(packageName, terserCode) {
        const destinationFile = path.join(configuration.jsDestination, packageName);
        logInfo("ᗘ JS compress save as " + destinationFile);
        try {
            const compressedJSCode = await minify(terserCode, terserOptions);
            if (compressedJSCode != null && compressedJSCode.code !== null) {
                if (!fsExtra.existsSync(destinationFolder)) {
                    fsExtra.mkdirSync(destinationFolder);
                }
                fsExtra.writeFileSync(destinationFile, compressedJSCode.code);
                totalBytesCopied = Buffer.byteLength(compressedJSCode.code);
                compressionStats.totalBytesCompressed += totalBytesCopied;
                const bytesSaved = totalBytesConsidered - totalBytesCopied;
                let statusMessage;
                if (bytesSaved > 9) {
                    statusMessage = "JS compression saved " + prettyBytes(bytesSaved) + " (" + ((bytesSaved / totalBytesConsidered) * 100).toFixed() + "%)";
                } else {
                    statusMessage = "JS is optimized";
                }
                logInfo("ᗘ JS compressed to " + destinationFile + " -- " + statusMessage);
            } else {
                logError("completeJSCompression something wrong with Terser " + compressedJSCode.error);
            }
        } catch (compressError) {
            logError("completeJSCompression Compress error " + compressError.toString());
        }
    }

    return new Promise(function (resolve) {
        logInfo("ᗘ Starting JavaScript optimization for PuttPuttPlanet app files");
        for (const fileGroup in fileGroups) {
            const fileList = fileGroups[fileGroup];
            const fileParts = path.parse(fileList[fileList.length - 1]);
            const packageName = fileParts.name + ".min.js";
            let terserCode = {};
            for (let index = 0; index < fileList.length; index += 1) {
                prepareJSFile(fileList[index], terserCode);
            }
            completeJSCompression(packageName, terserCode);
        }
        return resolve();
    });
}

/**
 * Create the compressed libraries.
 *
 * @param {object} varynConfiguration Configuration properties.
 */
async function optimizeJSLibs(varynConfiguration) {
    logInfo("ᗘ Starting JavaScript optimization for libraries");
    let totalBytesConsidered = 0;
    let terserCode = {};
    const sourcePath = varynConfiguration.jsSource;
    const destinationPath = varynConfiguration.jsDestination + "/lib/";
    const terserOptions = {
        warnings: true,
        toplevel: false
    };
    if ( ! varynConfiguration.isCompressJavaScript) {
        terserOptions.compress = false;
    }
    if ( ! varynConfiguration.isMangleJavaScript) {
        terserOptions.mangle = false;
    }

    return new Promise(function (resolve, reject) {
        fsExtra.ensureDir(destinationPath)
        .then(function () {
            asyncp.map(varynConfiguration.libsToCopy, function (file) {
                const fileName = path.join(sourcePath, "lib", file);
                fsExtra.stat(fileName, function (error, fileStat) {
                    if (error) {
                        throw (new Error("optimizeJSLibs fstat error -- " + fileName + ":  " + error.toString()));
                    } else {
                        const destinationFile = path.join(destinationPath, file);
                        const originalFileSize = fileStat.size;
                        fsExtra.copyFileSync(fileName, destinationFile);
                        logInfo("ᗘ " + fileName + " -- copied to " + destinationFile);
                        compressionStats.totalFiles++;
                        compressionStats.totalBytesConsidered += originalFileSize;
                        compressionStats.totalBytesCompressed += originalFileSize;
                    }
                });
            }, function (error, result) {
                if (error != null) {
                    return reject(error);
                } else {
                    return result;
                }
            });
        })
        .then(function (result) {
            const destinationFile = path.join(destinationPath, varynConfiguration.combinedLibFileName);
            varynConfiguration.libsToCombine.forEach(function (file) {
                const fileName = path.join(sourcePath, "lib", file);
                const fileContents = fsExtra.readFileSync(fileName, { encoding: "utf8", flag: "r" });
                if (fileContents != null && fileContents.length > 0) {
                    compressionStats.totalFiles++;
                    let fileSize = Buffer.byteLength(fileContents);
                    compressionStats.totalBytesConsidered += fileSize;
                    totalBytesConsidered += fileSize;
                    terserCode[file] = fileContents;
                }
            });
            minify(terserCode, terserOptions)
            .then(function(compressedJSCode) {
                fsExtra.writeFileSync(destinationFile, compressedJSCode.code);
                const totalBytesCopied = Buffer.byteLength(compressedJSCode.code);
                compressionStats.totalBytesCompressed += totalBytesCopied;
                const bytesSaved = totalBytesConsidered - totalBytesCopied;
                let statusMessage;
                if (bytesSaved > 9) {
                    statusMessage = "JS compression saved " + prettyBytes(bytesSaved) + "(" + ((bytesSaved / totalBytesConsidered) * 100).toFixed() + "%)";
                } else {
                    statusMessage = "JS is optimized";
                }
                logInfo("ᗘ Lib JS compressed to " + destinationFile + " -- " + statusMessage);
                return resolve(result);
            }, function(error) {
                logError("Minify error " + error.toString());
            })
            .catch(function(exception) {
                logError("Minify exception " + exception.toString());
            });
        })
        .catch(function(exception) {
            logError("fsExtra.ensureDir exception " + exception.toString());
        });
    });
}

/**
 * Display end of build statistics.
 */
function showStats() {
    logInfo(chalk.green("ᙘ ") + chalk.yellow("Build stats:"));
    const dateDiff = (compressionStats.endTime.getTime() - compressionStats.startTime.getTime()) / 1000;
    const bytesSaved = compressionStats.totalBytesConsidered - compressionStats.totalBytesCompressed;
    const bytesRatio = ((bytesSaved / compressionStats.totalBytesConsidered) * 100).toFixed();
    logInfo(chalk.green("ᙘ Completed build version " + version + " in " + dateDiff + "s: " + compressionStats.totalFiles + " files, originally " + prettyBytes(compressionStats.totalBytesConsidered) + ", now " + prettyBytes(compressionStats.totalBytesCompressed) + " saving " + prettyBytes(bytesSaved) + " (" + bytesRatio + "%)."));
}

/**
 * Update the build info and save it in a JSON file.
 * @param {string} buildFolder Destination folder to save file.
 * @param {string} buildFileName File name to use.
 */
function updateBuildInfoFile(buildFolder, buildFileName) {
    const buildFile = path.join(buildFolder, buildFileName);
    let gitHeadHash = "";
    try {
        gitHeadHash = execSync("git rev-parse HEAD", { encoding: "utf-8" });
    } catch (error) {
        console.error(chalk.red("Could not read git hash", error.message));
    }
    const buildInfo = {
        packageName: shell.env['npm_package_name'],
        version: shell.env['npm_package_version'],
        publish_date: (new Date()).toISOString(),
        commit: gitHeadHash
    };
    shell.echo(JSON.stringify(buildInfo)).to(buildFile);
}

/**
 * Run the build:
 *   - we can run in parallel css, js, html, and image optimizations
 *   - when those tasks are complete then run the copy files to distrib
 *   - after everything is done then show build statistics.
 */
function runBuild() {
    Promise.all([
        // optimizeCSS(configuration), // CSS is not working as of 2-Feb-2025
        optimizeJS(configuration),
        optimizeJSLibs(configuration),
        // optimizeHTML(configuration), // HTML is not working as of 2-Feb-2025
    ]).then(function (result) {
        updateBuildInfoFile(configuration.buildFolder, configuration.buildFileName);
        compressionStats.endTime = new Date();
        logInfo("ᙘ All builds complete");
        showStats(result);
    }).catch(function (error) {
        logError(error.toString() + " -- probably unhandled error.");
    });
}

updateConfiguration()
.then(function() {
    if (configuration.isLoggingInfo) {
        const compressMangle = (configuration.isCompressJavaScript ? "Yes" : "No") + "/" + (configuration.isMangleJavaScript ? "Yes" : "No");
        const logging = configuration.isLoggingInfo ? "Yes" : "No";
        logInfo("ᙘ Running build for version " + version + " with JS compress/mangle=" + compressMangle + "; logging=" + logging);
    }
    runBuild();
});
