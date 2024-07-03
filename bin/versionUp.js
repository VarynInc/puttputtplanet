/**
 * Update the version of the project by selectively updating specific files.
 * Files are specified in the array filesContainingVersion.
 * We expect to find a string "gameVersion: "#.#.#"," in the first file.
 * In each file specified, we replace the first occurrence of #.#.# with the new version number.
 **/

import fs from "fs";
import commandLineArgs from "yargs";
import { hideBin } from "yargs/helpers";

// The current version is based off the first file, incremented, and updated in all files:
const filesContainingVersion = [
    "package.json",
    "services/version.php"
];
let pathToRoot;
let debug = true;
let versionUpdateTask;

function debugLog(message) {
    if (debug) {
        console.log(message);
    }
}

function setParameters() {
    const options = commandLineArgs(hideBin(process.argv));
    if (typeof options.debug !== "undefined") {
        debug = options.debug;
    } else {
        debug = true;
    }
    if (typeof options.task !== "undefined") {
        versionUpdateTask = options.task;
    } else {
        versionUpdateTask = "build";
    }
    if (typeof options.path !== "undefined") {
        pathToRoot = options.path;
    } else {
        pathToRoot = "./";
    }
    debugLog("Options are: " + JSON.stringify({
            debug: debug,
            path: pathToRoot,
            task: versionUpdateTask,
            files: filesContainingVersion
        }));
}

function versionUp(task) {
   var major = 0;
   var minor = 0;
   var buildNumber = 0;
   var currentVersion = "";
   var nextVersion = "";
   var searchPos;
   var nextFile;
   var versionMatch = "[\"'][0-9]+\.[0-9]+\.[0-9]+[\"']";

   nextFile = pathToRoot + filesContainingVersion[0];
   fs.readFile(nextFile, "utf8", function(error, fileContents) {
       if (error != null) {
           debugLog("Error reading " + nextFile + " " + error.toString());
       } else {
           searchPos = fileContents.search(versionMatch);
           if (searchPos >= 0) {
               let startPos = searchPos + 1;
               currentVersion = fileContents.substring(
                   startPos,
                   startPos + fileContents.substring(startPos + 1).search("\"") + 1
               );
               console.log(currentVersion);
               nextVersion = currentVersion.split(".");
               major = parseInt(nextVersion[0], 10);
               minor = parseInt(nextVersion[1], 10);
               buildNumber = parseInt(nextVersion[2], 10);
               switch (task) {
                   case "major":
                       major ++;
                       minor = 0;
                       buildNumber = 0;
                       break;

                   case "minor":
                       minor ++;
                       buildNumber = 0;
                       break;

                   case "build":
                       buildNumber ++;
                       break;

                   default:
                       break;
                }
               nextVersion = major + "." + minor + "." + buildNumber;
               debugLog("Current version in " + nextFile + " is " + currentVersion + ". Next version will be " + nextVersion);

               filesContainingVersion.forEach(function(sourceFile) {
                   sourceFile = pathToRoot + sourceFile;
                   fs.readFile(sourceFile, "utf8", function (error, fileContent) {
                       if (error) {
                           debugLog("Reading file " + sourceFile + " fails with " + error.toString());
                       } else {
                           var regExp = new RegExp(versionMatch);
                           var posOfVersion = fileContent.search(regExp);
                           if (posOfVersion >= 0) {
                               fs.writeFile(
                                   sourceFile,
                                   fileContent.replace(regExp, "\"" + nextVersion + "\""),
                                   {
                                       encoding: "utf8"
                                   },
                                   function (fileError) {
                                       if (fileError != null) {
                                           debugLog("Writing file " + sourceFile + " fails with " + fileError.toString());
                                       } else {
                                           debugLog("Updated file " + sourceFile + " with version " + nextVersion);
                                       }
                                   });
                           } else {
                               debugLog("Version information not found in file " + sourceFile);
                           }
                       }
                   });
               });
            } else {
               debugLog("Current version is not found in " + nextFile);
           }
       }
   });
}

setParameters();
versionUp(versionUpdateTask);
