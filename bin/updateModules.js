/**
 * Copy the common module files from their source folder to this project.
 */
import path from "path";
import chalk from "chalk";
import fsExtra from "fs-extra";

const configuration = {
    isLoggingInfo: true,
    isLoggingError: true,
    destinationPath: "./",
    librariesSourcePath: "../../libraries/",
    librariesManifest: [
        {
            source: "EnginesisSDK/enginesis-php/source/common.php",
            destination: "./services/common.php"
        },
        {
            source: "EnginesisSDK/enginesis-php/source/Enginesis.php",
            destination: "./services/Enginesis.php"
        },
        {
            source: "EnginesisSDK/enginesis-php/source/EnginesisErrors.php",
            destination: "./services/EnginesisErrors.php"
        },
        {
            source: "EnginesisSDK/enginesis-js/js/enginesis.mjs",
            destination: "./public/js/lib/enginesis.js"
        },
        {
            source: "commonUtilities/js/commonUtilities.mjs",
            destination: "./public/js/lib/commonUtilities.js"
        }
    ]
};

/**
 * Helper function to control logging.
 * @param {string} message
 */
function logInfo(message) {
    if (configuration.isLoggingInfo) {
        console.log(message);
    }
}
/**
 * Helper function to control logging.
 * @param {string} message
 */
function logError(message) {
    if (configuration.isLoggingError) {
        console.warn(chalk.red("ᚎ " + message));
    }
}

async function updateModuleFiles() {
    configuration.librariesManifest.forEach(async function(fileProperties) {
        const sourceFile = path.join(configuration.librariesSourcePath, fileProperties.source);
        let destinationFile;
        if (fileProperties.destination.startsWith("/") || fileProperties.destination.startsWith("./") || fileProperties.destination.startsWith("../")) {
            destinationFile = fileProperties.destination;
        } else {
            destinationFile = path.join(configuration.destinationPath, fileProperties.destination);
        }
        try {
            await fsExtra.copy(sourceFile, destinationFile);
            logInfo(`Copied ${sourceFile} to ${destinationFile}`);
        } catch (copyError) {
            logError(`Error trying to copy ${sourceFile} to ${destinationFile}: ` + copyError.toString());
        }
    });
}

updateModuleFiles();
