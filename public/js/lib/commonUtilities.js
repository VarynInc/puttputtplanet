/**  commonUtilities.js
 *
 * @module commonUtilities
 * @classdesc
 *   A static object of utility functions for handling common problems
 *   found in JavaScript and web development. I find on every JS project I work
 *   on I need most of these functions, so I pulled them all together in one place.
 *
 *   This module includes many function utilities for data transformations such as
 *   base64, url, and query string processing, data validation, and storage handling.
 *
 * @since 1.0
 * @exports commonUtilities
 */

export default {
    version: "1.7.5",
    _base64KeyStr: "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=",
    _testNumber: 0,

    /**
     * Determine if the current context is a browser. This assures we have access to window and document.
     * Otherwise it assumes it is a Node environment and we don't have access to the browser environment.
     * @returns {boolean} True if running in a browser environment.
     */
    isBrowserEnvironment: function() {
        return typeof window === "object" && typeof document === "object";
    },

    /**
     * Determine if HTML5 local or session storage is available.
     * @param {string} storageType - either "localStorage" or "sessionStorage", default is "localStorage".
     * @param {boolean} robustCheck - true for the more robust but un-performant test.
     * @returns {boolean} True if the storage type is supported.
     */
    browserStorageAvailable: function(storageType, robustCheck) {
        if ( ! this.isBrowserEnvironment()) {
            return false;
        }
        let hasSupport = false;
        let storage;
        let testKey;

        if (storageType === undefined || storageType == null || storageType == "") {
            storageType = "localStorage";
        }
        try {
            hasSupport = storageType in window && window[storageType] !== null;
            if (hasSupport && robustCheck) {
                // even if "supported" make sure we can write and read from it
                storage = window[storageType];
                testKey = "commonUtilities";
                storage.setItem(testKey, "1");
                storage.removeItem(testKey);
            }
        } catch (exception) {
            hasSupport = false;
        }
        return hasSupport;
    },

    /**
     * Coerce a boolean value to its string representation, either "true" or "false". The input
     * parameter is expected to be a boolean but if it isn't it is coerced to its boolean representation.
     * @param {boolean} value Expected boolean value to be converted to a printable string, either "true" or "false".
     * @returns {string} Either "true" or "false".
     */
    booleanToString: function(value) {
        return ( ! ! value) ? "true" : "false";
    },

    /**
     * Return the provided object represented as a string in "key: value;" format. Typically
     * used for debug and user display. For serialization it is preferred to convert
     * objects to JSON.
     *
     * @param {object} object The object to convert to a string representation.
     * @return {string} string The object converted to a string representation.
     */
    objectToString: function (object) {
        var result,
            prop;
        if (object) {
            result = "";
            for (prop in object) {
                if (object.hasOwnProperty(prop)) {
                    result += (result.length > 0 ? " " : "") + prop + ": " + object[prop] + ";";
                }
            }
        } else {
            result = "null;";
        }
        return result;
    },

    /**
     * Return the provided array as a string in key: value; format.
     *
     * @param {array} array The array to convert to a string representation.
     * @return {string} string The array converted to a string representation.
     */
    arrayToString: function (array) {
        var result,
            key,
            value;
        if (array && array instanceof Array) {
            result = "[";
            for (key in array) {
                value = array[key];
                if (typeof(value) == "undefined") {
                    value = "undefined";
                } else if (Array.isArray(value)) {
                    value = this.arrayToString(value);
                } else if (typeof(value) == "object") {
                    value = this.objectStringify(value);
                }
                result += (result.length > 1 ? ", " : "") + key + ": " + value;
            }
            result += "]";
        } else {
            result = "null";
        }
        return result;
    },

    /**
     * Return the provided object as a string in key: value; format. This version handles
     * functions but is slower than objectToString.
     *
     * @param {object} object The object to convert to a string representation.
     * @return {string} string The object converted to a string representation.
     */
    objectStringify: function (object) {
        var subObjects = [], // An array of sub-objects that will later be joined into a string.
            property;

        if (object === undefined || object === null) {
            return String(object);
        } else if (typeof(object) == "function") {
            subObjects.push(object.toString());
        } else if (typeof(object) == "object") {
            // is object (or array):
            //    Both arrays and objects seem to return "object" when typeof(obj)
            //    is applied to them. So instead we check if they have the property
            //    join, a function of the array prototype. Unless the object actually
            //    defines its own join property!
            if (object.join === undefined) {
                for (property in object) {
                    if (object.hasOwnProperty(property)) {
                        subObjects.push(property + ": " + this.objectStringify(object[property]));
                    }
                }
                return "{" + subObjects.join(", ") + "}";
            } else {
                for (property in object) {
                    subObjects.push(this.objectStringify(object[property]));
                }
                return "[" + subObjects.join(", ") + "]";
            }
        } else {
            // all other value types can be represented with JSON.stringify
            subObjects.push(JSON.stringify(object))
        }
        return subObjects.join(", ");
    },

    /**
     * Return the current document query string as an object with
     * key/value pairs converted to properties.
     *
     * @param {string|null} urlParameterString An optional query string to parse as the query string. If not
     *   provided then use window.location.search.
     * @return {object} result The query string converted to an object of key/value pairs.
     */
    queryStringToObject: function (urlParameterString) {
        const search = /([^&=]+)=?([^&]*)/g;
        let match;
        let result = {};

        function unescapeURI (uri) {
            return decodeURIComponent(uri.replace(/\+/g, " "));
        };

        if (urlParameterString) {
            if (urlParameterString[0] == "?") {
                urlParameterString = urlParameterString.substring(1);
            }
        } else if (window) {
            urlParameterString = window.location.search.substring(1);
        }
        while (match = search.exec(urlParameterString)) {
            result[unescapeURI(match[1])] = unescapeURI(match[2]);
        }
        return result;
    },

    /**
     * Append an existing URL with additional query parameters.
     * @param {String} url A well-formed URL. It may or may not have "?" query parameter(s).
     * @param {Object} parameters Expected object of key/value properties. Does not work for nested objects.
     * @returns {String} The url with query string parameters appended.
     */
    appendQueryParametersToURL: function (url, parameters) {
        var queryPos = url.indexOf("?");
        var safeParameters = [];
        for (var parameter in parameters) {
            if (parameters.hasOwnProperty(parameter)) {
                safeParameters.push(encodeURIComponent(parameter) + "=" + encodeURIComponent(parameters[parameter]));
            }
        }
        if (queryPos > 0 && queryPos < url.length - 1) {
            url += "&";
        } else if (queryPos == -1) {
            url += "?";
        }
        url += safeParameters.join("&");
        return url;
    },

    /**
     * Extend an object with properties copied from other objects. Takes a variable number of arguments:
     * @param {any} ...arguments
     *  If no arguments, an empty object is returned.
     *  If one argument, that object is returned unchanged.
     *  If more than one argument, each object in l-2-r order is copied to the first object one property at a time. When
     *    properties conflict the last property is the one retained.
     * @returns {object}
     */
    extendObject: function() {
        var key,
            value,
            extendedObject,
            object,
            objects,
            index,
            objectCount;

        if (arguments.length > 0) {
            extendedObject = arguments[0];
            if (arguments.length > 1) {
                objects = arguments.slice(1);
                for (index = 0, objectCount = objects.length; index < objectCount; index ++) {
                    object = objects[index];
                    for (key in object) {
                        value = object[key];
                        extendedObject[key] = value;
                    }
                }
            }
        } else {
            extendedObject = {};
        }
        return extendedObject;
    },

    /**
     * Determine if at least one string in the array matches the pattern. Since we are using regex pattern
     * to match we cannot use Array.indexOf(). If the pattern were a simple string, use Array.indexOf().
     * @param {Regex} pattern a regex pattern to match.
     * @param {Array} arrayOfStrings strings to test each against the pattern.
     * @returns {number} index of first string in the array that matches the pattern, -1 when no match.
     */
    matchInArray: function (pattern, arrayOfStrings) {
        var i = 0,
            numberOfTokens;

        if (pattern && arrayOfStrings && arrayOfStrings.constructor === Array) {
            numberOfTokens = arrayOfStrings.length;
            for (i; i < numberOfTokens; i ++) {
                if (pattern.match(arrayOfStrings[i])) {
                    return i;
                }
            }
        }
        return -1;
    },

    /**
     * Count the occurrences of each unique value in an array values. It is expected
     * the array contains only scalar values (Numbers, Strings.)
     * @param {Array} array An array of scalar values.
     * @returns {Object} An object where each property is a unique value from the array, and its value is
     * the count of the number of times that value appears in the array.
     */
    arrayCount: function(array) {
        return array.reduce(function(accumulator, value) {
            accumulator[value] = (accumulator[value] || 0) + 1;
            return accumulator;
        }, {});
    },

    /**
     * Given a path make sure it represents a full path with a leading and trailing /.
     *
     * @param {string} path URI path to check.
     * @return {string} path Full URI path.
     */
    makeFullPath: function (path) {
        if (path) {
            if (path[path.length - 1] !== "/") {
                path += "/";
            }
            if (path[0] !== "/") {
                path = "/" + path;
            }
        } else {
            path = "/";
        }
        return path;
    },

    /**
     * Append a folder or file name to the end of an existing path string.
     *
     * @param {string} path URI path to append to.
     * @param {string} file folder or file to append.
     * @return {string} path Full URI path.
     */
    appendFileToPath: function (path, file) {
        if (path && file) {
            if (path[path.length - 1] !== "/" && file[0] !== "/") {
                path += "/" + file;
            } else if (path[path.length - 1] == "/" && file[0] == "/") {
                path += file.substring(1);
            } else {
                path += file;
            }
        } else if (file) {
            path = file;
        }
        return path;
    },

    /**
     * Replace occurrences of {token} with matching keyed values from parameters array.
     *
     * @param {string} text text containing tokens to be replaced.
     * @param {Array} parameters array/object of key/value pairs to match keys as tokens in text and replace with value.
     * @return {string} text replaced string.
     */
    tokenReplace: function (text, parameters) {
        var token,
            regexMatch;

        for (token in parameters) {
            if (parameters.hasOwnProperty(token)) {
                regexMatch = new RegExp("\{" + token + "\}", "g");
                text = text.replace(regexMatch, parameters[token]);
            }
        }
        return text;
    },

    /**
     * Translate single characters of an input string.
     *
     * @param {String} string to translate. It is not mutated.
     * @param {Array} undesired characters to translate from in string.
     * @param {Array} desired characters to translate to in string.
     * @returns {String} the translated string.
     */
    stringTranslate: function(string, undesired, desired) {
        var i;
        var char;
        var found;
        var length;
        var result = "";
        if (typeof string !== "string" || string.length < 1 || ! Array.isArray(undesired) || ! Array.isArray(desired) || undesired.length != desired.length) {
            return string;
        }
        length = string.length;
        for (i = 0; i < length; i ++) {
            char = string.charAt(i);
            found = undesired.indexOf(char);
            if (found >= 0) {
                char = desired[found];
            }
            result += char;
        }
        return result;
    },

    /**
     * Determine if a given variable is considered an empty value. A value is considered empty if it is any one of
     * `null`, `undefined`, `false`, `NaN`, an empty string, an empty array, or 0. Note this does not consider an
     * empty object `{}` to be empty.
     * @param {any} field The parameter to be tested for emptiness.
     * @returns {boolean} `true` if `field` is considered empty.
     */
    isEmpty: function (field) {
        return field === undefined
            || field === null
            || field === false
            || (typeof field === "string" && (field === "" || field === "null" || field === "NULL"))
            || (field instanceof Array && field.length == 0)
            || (typeof field === "number" && (isNaN(field) || field === 0));
    },

    /**
     * Determine if a given variable is considered null (either null or undefined).
     * At the moment this will not check for "null"/"NULL" values, as when using SQL.
     * @param {any} field A value to consider.
     * @returns {boolean} `true` if `value` is considered null.
     */
    isNull: function(field) {
        return field === undefined || field === null;
    },

    /**
     * Coerce a value to its boolean equivalent, causing the value to be interpreted as its
     * boolean intention. This works very different than the JavaScript coercion. For example,
     * "0" == true and "false" == true in JavaScript but here "0" == false and "false" == false.
     * @param {*} value A value to test.
     * @returns {boolean} `true` if `value` is considered a coercible true value.
     */
    coerceBoolean: function(value) {
        if (typeof value === "string") {
            value = value.toLowerCase();
            return value === "1" || value === "true" || value === "t" || value === "checked" || value === "yes" || value === "y";
        } else {
            return value === true || value === 1;
        }
    },

    /**
     * Given a list of parameters, return the first parameter that is considered not empty.
     * See `isEmpty` for the meaning of "empty".
     * @param  {...any} parameters An arbitrary set of function parameters to test for emptiness.
     * @returns {any} The first function parameter that is considered not empty.
     */
    coalesceNotEmpty: function(...parameters) {
        const commonUtilities = this;
        if ( ! parameters || parameters.length < 1) {
            return undefined;
        }
        return parameters.find(function(value) {
            return ! commonUtilities.isEmpty(value);
        });
    },

    /**
     * Given a list of parameters, return the first parameter that is considered not null.
     * See `isNull` for the meaning of null.
     * @param  {...any} parameters An arbitrary set of function parameters to test for nullness.
     * @returns {any} The first function parameter that is considered not null.
     */
     coalesceNotNull: function(...parameters) {
        const commonUtilities = this;
        if ( ! parameters || parameters.length < 1) {
            return undefined;
        }
        return parameters.find(function(value) {
            return ! commonUtilities.isNull(value);
        });
    },

    /**
     * Convert a string into one that has no HTML vulnerabilities such that it can be rendered inside an HTML tag.
     * @param {string} string A string to check for HTML vulnerabilities.
     * @returns {string} A copy of the input string with any HTML vulnerabilities removed.
     */
    safeForHTML: function (string) {
        var htmlEscapeMap = {
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#x27;",
                "/": "&#x2F;"
            },
            htmlEscaper = /[&<>"'\/]/g;
        return ("" + string).replace(htmlEscaper, function (match) {
            return htmlEscapeMap[match]
        });
    },

    /**
     * Convert any string into a string that can be used as a DOM id (aka slug). Rules:
     *   * Only allow A-Z, a-z, 0-9, dash, space.
     *   * Trim any leading or trailing space.
     *   * Only lowercase characters.
     *   * Max length 50.
     *
     * For example, the string
     *    "This is   +a TEST" is changed to "this-is-a-test". Spaces and multiple spaces change
     *    to -, special chars are removed, and the string is all lowercase.
     *
     * @param {string} label A string to consider.
     * @returns {string} The converted string.
     */
    makeSafeForId: function (label) {
        if (typeof label !== "string") {
            if (label !== undefined && label !== null) {
                label = label.toString();
            } else {
                label = "id";
            }
        }
        label = label.trim();
        if (label.length > 0) {
            return label.replace(/-/g, " ").replace(/[^\w\s]/g, "").replace(/\s\s+/g, " ").replace(/\s/g, "-").toLowerCase().substr(0, 50);
        } else {
            return "id";
        }
    },

    /* ----------------------------------------------------------------------------------
     * Platform and feature detection
     * ----------------------------------------------------------------------------------*/
    /**
     * Determine if the current UA environment is a touch device.
     *
     * @return {bool} true if we think this device has a touch screen, false if we think otherwise.
     *
     */
    isTouchDevice: function () {
        if (window && (('ontouchstart' in window) || window.TouchEvent || (window.DocumentTouch && document instanceof DocumentTouch))) {
            return true;
        }
        return false;
    },

    /**
     * Determine if the current UA environment is a mobile device.
     *
     * @return {bool} true if we think this is a mobile device, false if we think otherwise.
     *
     */
    isMobile: function () {
        return (this.isMobileAndroid() || this.isMobileIos());
    },

    isMobileAndroid: function () {
        if (navigator && navigator.userAgent.match(/Android/i)) {
            // NOTE: tolower+indexof is about 10% slower than regex
            // return navigator.userAgent.toLowerCase().indexOf("android") != -1;
            return true;
        }
        return false;
    },

    isMobileIos: function () {
        if (navigator && navigator.userAgent.match(/iPhone|iPad|iPod/i)) {
            return true;
        }
        return false;
    },

    /**
     * On some platforms, web audio doesn't work until a user-initiated event occurs. This function
     * plays a short silent clip in order to unlock the audio capabilities. This function returns a
     * HTMLAudio element that you must call the play method on once you detected the user interacted
     * (e.g. a tap event) with your app the very first time.
     * @returns HTMLAudio An audio element that you call .play() on in order to unlock audio.
     */
    unlockWebAudio: function () {
        var silence = "data:audio/mpeg;base64,//uQxAAAAAAAAAAAAAAAAAAAAAAASW5mbwAAAA8AAAADAAAGhgBVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVWqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqr///////////////////////////////////////////8AAAA5TEFNRTMuOThyAc0AAAAAAAAAABSAJAiqQgAAgAAABobxtI73AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA//uQxAACFEII9ACZ/sJZwWEoEb8w/////N//////JcxjHjf+7/v/H2PzCCFAiDtGeyBCIx7bJJ1mmEEMy6g8mm2c8nrGABB4h2Mkmn//4z/73u773R5qHHu/j/w7Kxkzh5lWRWdsifCkNAnY9Zc1HvDAhjhSHdFkHFzLmabt/AQxSg2wwzLhHIJOBnAWwVY4zrhIYhhc2kvhYDfQ4hDi2Gmh5KyFn8EcGIrHAngNgIwVIEMf5bzbAiTRoAD///8z/KVhkkWEle6IX+d/z4fvH3BShK1e5kmjkCMoxVmXhd4ROlTKo3iipasvTilY21q19ta30/v/0/idPX1v8PNxJL6ramnOVsdvMv2akO0iSYIzdJFirtzWXCZicS9vHqvSKyqm5XJBdqBwPxyfJdykhWTZ0G0ZyTZGpLKxsNwwoRhsx3tZfhwmeOBVISm3impAC/IT/8hP/EKEM1KMdVdVKM2rHV4x7HVXZvbVVKN/qq8CiV9VL9jjH/6l6qf7MBCjZmOqsAibjcP+qqqv0oxqpa/NVW286hPo1nz2L/h8+jXt//uSxCmDU2IK/ECN98KKtE5IYzNoCfbw+u9i5r8PoadUMFPKqWL4LK3T/LCraMSHGkW4bpLXR/E6LlHOVQxmslKVJ8IULktMN06N0FKCpHCoYsjC4F+Z0NVqdNFoGSTjSiyjzLdnZ2fNqTi2eHKONONKLMPMKLONKLMPQRJGlFxZRoKcJFAYEeIFiRQkUWUeYfef//Ko04soswso40UJAgMw8wosososy0EalnZyjQUGBRQGIFggOWUacWUeYmuadrZziQKKEgQsQLAhQkUJAgMQDghltLO1onp0cpkNInSFMqlYeSEJ5AHsqFdOwy1DA2sRmRJKxdKRfLhfLw5BzUxBTUUzLjk4LjJVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVUxBTUUzLjk4LjJVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVf/7ksRRA8AAAaQAAAAgAAA0gAAABFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVU=";
        var audioTag = document.createElement("audio");
        audioTag.controls = false;
        audioTag.preload = "auto";
        audioTag.loop = false;
        audioTag.src = silence;
        document.addEventListener("visibilitychange", function () {
            if (document.visibilityState == 'visible') {
                audioTag.play();
            }
        });
        return audioTag;
    },

    /* ----------------------------------------------------------------------------------
     * Various conversion utilities - UTF-8, Base 64
     * ----------------------------------------------------------------------------------*/

    /**
     * Encode a Unicode string in UTF-8 character encoding.
     *
     * @param {string} input string in Unicode to convert to UTF-8.
     * @return {string} result UTF-8 encoded input string.
     */
    utf8Encode: function (input) {
        var result = "",
            inputLength = input.length,
            index,
            charCode;
        input = input.replace(/\r\n/g,"\n");

        for (index = 0; index < inputLength; index ++) {
            charCode = input.charCodeAt(index);
            if (charCode < 128) {
                result += String.fromCharCode(charCode);
            } else if((charCode > 127) && (charCode < 2048)) {
                result += String.fromCharCode((charCode >> 6) | 192);
                result += String.fromCharCode((charCode & 63) | 128);
            } else {
                result += String.fromCharCode((charCode >> 12) | 224);
                result += String.fromCharCode(((charCode >> 6) & 63) | 128);
                result += String.fromCharCode((charCode & 63) | 128);
            }
        }
        return result;
    },

    /**
     * Decode a UTF-8 encoded string into a Unicode character coding format.
     *
     * @param {string} utfText string in UTF-8 to convert to Unicode.
     * @return {string} result Unicode representation of input string.
     */
    utf8Decode: function (utfText) {
        var result = "",
            utfTextLength = utfText.length,
            index = 0,
            charCode1,
            charCode2,
            charCode3;

        while (index < utfTextLength) {
            charCode1 = utfText.charCodeAt(index);
            if (charCode1 < 128) {
                result += String.fromCharCode(charCode1);
                index ++;
            } else if((charCode1 > 191) && (charCode1 < 224)) {
                charCode2 = utfText.charCodeAt(index + 1);
                result += String.fromCharCode(((charCode1 & 31) << 6) | (charCode2 & 63));
                index += 2;
            } else {
                charCode2 = utfText.charCodeAt(index + 1);
                charCode3 = utfText.charCodeAt(index + 2);
                result += String.fromCharCode(((charCode1 & 15) << 12) | ((charCode2 & 63) << 6) | (charCode3 & 63));
                index += 3;
            }
        }
        return result;
    },

    /**
     * Convert an image located at the URL specified into its Base 64 representation.
     * Because the image is loaded asynchronously over the network a callback function
     * will be called once the image is loaded and encoded.
     *
     * @param {string} url URL to an image.
     * @param {function} callback Called when image is loaded. This function takes one parameter,
     *         a string that represents the Base 64 encoded image.
     * @return void
     */
    base64FromImageUrl: function(url, callback) {
        var img = new Image();
        img.src = url;
        img.onload = function() {
            var canvas = document.createElement("canvas"),
                ctx = canvas.getContext("2d"),
                dataURL;

            canvas.width = this.width;
            canvas.height = this.height;
            ctx.drawImage(this, 0, 0);
            dataURL = canvas.toDataURL("image/png");
            callback(dataURL);
        }
        img.onerror = function() {
            callback(null);
        }
    },

    /**
     * Encode a string into its base 64 representation.
     *
     * @param {string} input string to encode in base 64.
     * @return {string} output encoded string.
     */
    base64Encode: function (input) {
        let output = "";
        let chr1, chr2, chr3, enc1, enc2, enc3, enc4;
        let i = 0;

        input = this.utf8Encode(input);
        const inputLength = input.length;
        while (i < inputLength) {
            chr1 = input.charCodeAt(i ++);
            chr2 = input.charCodeAt(i ++);
            chr3 = input.charCodeAt(i ++);
            enc1 = chr1 >> 2;
            enc2 = ((chr1 & 3) << 4) | (chr2 >> 4);
            enc3 = ((chr2 & 15) << 2) | (chr3 >> 6);
            enc4 = chr3 & 63;
            if (isNaN(chr2)) {
                enc3 = enc4 = 64;
            } else if (isNaN(chr3)) {
                enc4 = 64;
            }
            output = output +
                this._base64KeyStr.charAt(enc1) + this._base64KeyStr.charAt(enc2) +
                this._base64KeyStr.charAt(enc3) + this._base64KeyStr.charAt(enc4);
        }
        return output;
    },

    /**
     * Convert a base 64 encoded string to its UTF-8 character coding.
     *
     * @param {string} input string in base 64 to convert to UTF-8.
     * @return {string} result UTF-8 string.
     */
    base64Decode: function (input) {
        let output = "";
        let chr1, chr2, chr3, enc1, enc2, enc3, enc4;
        let i = 0;

        input = input.replace(/[^A-Za-z0-9\+\/\=]/g, "");
        const inputLength = input.length;
        while (i < inputLength) {
            enc1 = this._base64KeyStr.indexOf(input.charAt(i ++));
            enc2 = this._base64KeyStr.indexOf(input.charAt(i ++));
            enc3 = this._base64KeyStr.indexOf(input.charAt(i ++));
            enc4 = this._base64KeyStr.indexOf(input.charAt(i ++));
            chr1 = (enc1 << 2) | (enc2 >> 4);
            chr2 = ((enc2 & 15) << 4) | (enc3 >> 2);
            chr3 = ((enc3 & 3) << 6) | enc4;
            output = output + String.fromCharCode(chr1);
            if (enc3 != 64) {
                output = output + String.fromCharCode(chr2);
            }
            if (enc4 != 64) {
                output = output + String.fromCharCode(chr3);
            }
        }
        return this.utf8Decode(output);
    },

    /**
     * Replace base-64 chars that are not URL safe. This will help transmit a base 64 string
     * over the internet by translating '+/=' into '-_~'.
     * @param {string} data A string of base 64 characters to translate.
     * @return {string} Translates '+/=' found in data to '-_~'.
     */
    base64URLEncode: function (data) {
        return data
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/\=/g, '~');
    },

    /**
     * Replace base-64 chars that are not URL safe. This will help transmit a base 64 string
     * over the internet by translating '-_~' into '+/='.
     * @param {string} data A string of translated base 64 characters to translate back to true base-64.
     * @return {string} Translates '-_~' found in $data to '+/='.
     */
    base64URLDecode: function (data) {
        return data
        .replace(/\-/g, '+')
        .replace(/\_/g, '/')
        .replace(/\~/g, '=');
    },

    /**
     * Convert a binary byte array into its base 64 representation. This will handle
     * any type of array buffer, converting it to unsigned 8 bit integer, and then
     * mapping each 64 bit string to its base 64 representation. See also `base64ToArrayBuffer`.
     * @param {ArrayBuffer} arrayBuffer An array of bytes.
     * @return {String} The base 64 string representation of the input array.
     */
    arrayBufferToBase64: function(arrayBuffer) {
        return btoa(String.fromCharCode.apply(null, new Uint8Array(arrayBuffer)));
    },

    /**
     * Convert a base 64 string to a binary byte array. This will convert it to unsigned
     * 8 bit integer array. This is the complement to `arrayBufferToBase64`.
     * @param {String} base64String A string of base 64 data.
     * @return {ArrayBuffer} The binary representation of the base 64 string.
     */
    base64ToArrayBuffer: function(base64String) {
        return Uint8Array.from(atob(base64String), function(char) { return char.charCodeAt(0) });
    },

    /**
     * Convert a string of hexadecimal digits to its binary byte array equivalent. For
     * example, "FF00" returns [255, 0] as a Uint8Array.
     * @param {string} hexString A string of hexadecimal digits. Must be even length and
     *   contain only hex digits.
     * @returns {Uint8Array|null} Returns an array of the binary representation of the hex string,
     *   or null if there was an error where the input string could not be reliably converted.
     */
    hexStringToByteArray: function(hexString) {
        const stringLength = hexString.length;
        if (stringLength % 2 == 1) {
            // it must be an even number of hex digits, it is an error otherwise
            return null;
        }
        if (hexString.match(/[^0-9A-Fa-f]/)) {
            // it must contain only hex digits
            return null;
        }
        let bytes = [];
        let index = 0
        for (; index < stringLength; index += 2) {
            bytes.push(parseInt(hexString.substring(index, index + 2), 16));
        }
        return new Uint8Array(bytes);
    },

    /**
     * Convert a string into its binary equivalent. This takes each byte of the string
     * and converts it to its binary value (i.e. code point.)
     * @param {String} inputString A string to convert to binary.
     * @returns {Uint8Array} The binary representation of the input string.
     */
    stringToByteArray: function(inputString) {
        const utf8Encode = new TextEncoder();
        return utf8Encode.encode(inputString);
    },

    /**
     * Convert a binary byte array into its string equivalent. This takes each element (byte)
     * of the array and converts it to its string value (i.e. code point.)
     * @param {Uint8Array} inputArray An array to convert to a string.
     * @returns {String} The string representation of the input array.
     */
    byteArrayToString: function(inputArray) {
        const utf8Decode = new TextDecoder();
        return utf8Decode.decode(inputArray);
    },

    /**
     * Convert an array of bytes into it hexadecimal string equivalent. For example,
     * the array [255, 0] will return "FF00".
     * @param {ArrayBuffer} byteArray An array of bytes, preferably unsigned 8-bit integers (Uint8Array).
     * @returns {String} A string of hex digits.
     */
    byteArrayToHexString: function(byteArray) {
        if ( ! (ArrayBuffer.isView(byteArray) || Array.isArray(byteArray))) {
            return "";
        }
        const arrayLength = byteArray.length;
        const hexDigits = new Uint8Array(arrayLength * 2);
        const alpha = 'a'.charCodeAt(0) - 10;
        const digit = '0'.charCodeAt(0);
        let p = 0;
        let nibble;
        for (let i = 0; i < arrayLength; i += 1) {
            nibble = byteArray[i] >>> 4;
            hexDigits[p ++] = nibble > 9 ? nibble + alpha : nibble + digit;
            nibble = byteArray[i] & 0xF;
            hexDigits[p ++] = nibble > 9 ? nibble + alpha : nibble + digit;
        }
        return String.fromCharCode.apply(null, hexDigits);
    },

    /**
     * Round a number to the requested number of decimal places.
     * @param {number} value the number to round.
     * @param {integer} decimalPlaces the number of decimal places.
     * @returns {number} Rounded value.
     */
    roundTo: function (value, decimalPlaces) {
        const orderOfMagnitude = Math.pow(10, decimalPlaces);
        return Math.round(value * orderOfMagnitude) / orderOfMagnitude;
    },

    /* ----------------------------------------------------------------------------------
     * Cookie handling functions
     * ----------------------------------------------------------------------------------*/

    /**
     * Return the contents of the cookie indexed by the specified key.
     *
     * @param {string} key Indicate which cookie to get.
     * @return {string|null} Contents of cookie stored with key.
     */
    cookieGet: function (key) {
        if ( ! this.isBrowserEnvironment()) {
            return null;
        }
        if (key && document.cookie) {
            return decodeURIComponent(document.cookie.replace(new RegExp("(?:(?:^|.*;)\\s*" + encodeURIComponent(key).replace(/[\-\.\+\*]/g, "\\$&") + "\\s*\\=\\s*([^;]*).*$)|^.*$"), "$1")) || null;
        } else {
            return null;
        }
    },

    /**
     * Set a cookie indexed by the specified key.
     *
     * @param {String} key Indicate which cookie to set.
     * @param {String|object|null} value Value to store under key. If null, expire the prior cookie.
     * @param {Number|String|Date} expiration When the cookie should expire. Number indicates
     *   max age, in seconds. String indicates GMT date. Date is converted to GMT date.
     * @param {String} path Cookie URL path.
     * @param {String} domain Cookie domain.
     * @param {Boolean} isSecure Set cookie secure flag. Default is true.
     * @return {Boolean|String} true if set, false if error. Returns string if not running in
     *   a browser environment, such as Node.
     */
     cookieSet: function (key, value, expiration, path, domain, isSecure) {
        if ( ! this.isBrowserEnvironment()) {
            return null;
        }

        let expires;
        let neverExpires;
        let sameSite;

        if ( ! key || /^(?:expires|max\-age|path|domain|secure)$/i.test(key)) {
            // This is an invalid cookie key.
            return false;
        }
        if (value === null || typeof value === "undefined") {
            return this.cookieRemove(key, path, domain);
        }
        expires = "";
        neverExpires = "expires=Fri, 31 Dec 9999 23:59:59 GMT";
        sameSite = "samesite=lax";
        if (typeof isSecure === "undefined") {
            isSecure = true;
        }
        if (typeof value === "object") {
            value = JSON.stringify(value);
        }
        if (expiration) {
            switch (expiration.constructor) {
            case Number:
                expires = expiration === Infinity ? neverExpires : "; max-age=" + expiration;
                break;
            case String:
                expires = "expires=" + expiration;
                break;
            case Date:
                expires = "expires=" + expiration.toUTCString();
                break;
            default:
                expires = neverExpires;
                break;
            }
        } else {
            expires = neverExpires;
        }
        const cookieData = encodeURIComponent(value) + "; "
            + expires
            + (domain ? (";domain=" + domain) : "")
            + (path ? (";path=" + path) : "")
            + ";" + sameSite
            + (isSecure ? ";Secure" : "");
        if (typeof document === "undefined" || typeof document.cookie === "undefined") {
            // If the document object is undefined then we are running in Node.
            return cookieData;
        }
        document.cookie = encodeURIComponent(key) + "=" + cookieData;
        return true;
    },

    /**
     * Remove a cookie indexed by the specified key.
     *
     * @param {string} key Indicate which cookie to remove.
     * @param {string} path Cookie URL path.
     * @param {string} domain Cookie domain.
     * @return {boolean} true if removed, false if doesn't exist.
     */
    cookieRemove: function (key, path, domain) {
        if (this.cookieExists(key)) {
            document.cookie = encodeURIComponent(key) + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT" + (domain ? "; domain=" + domain : "") + (path ? "; path=" + path : "");
            return true;
        } else {
            return false;
        }
    },

    /**
     * Determine if the cookie exists.
     *
     * @param {string} key Key to test if exists.
     * @return {boolean} true if exists, false if doesn't exist.
     */
    cookieExists: function (key) {
        if ( ! this.isBrowserEnvironment()) {
            return false;
        }
        if (key && document.cookie) {
            return (new RegExp("(?:^|;\\s*)" + encodeURIComponent(key).replace(/[\-\.\+\*]/g, "\\$&") + "\\s*\\=")).test(document.cookie);
        } else {
            return false;
        }
    },

    /**
     * Return an array of all cookie keys.
     *
     * @return {Array} Array of all stored cookie keys.
     */
    cookieGetKeys: function () {
        if ( ! this.isBrowserEnvironment()) {
            return [];
        }
        var allKeys = document.cookie.replace(/((?:^|\s*;)[^\=]+)(?=;|$)|^\s*|\s*(?:\=[^;]*)?(?:\1|$)/g, "").split(/\s*(?:\=[^;]*)?;\s*/),
            count = allKeys.length,
            index = 0;

        for (; index < count; index ++) {
            allKeys[index] = decodeURIComponent(allKeys[index]);
        }
        return allKeys;
    },

    /* ----------------------------------------------------------------------------------
     * Local storage helper functions
     * ----------------------------------------------------------------------------------*/

    /**
     * Determine if we have sessionStorage available.
     * @returns {boolean}
     */
    haveSessionStorage: function () {
        return this.browserStorageAvailable("sessionStorage", true);
    },

    /**
     * Determine if we have localStorage available.
     * @returns {boolean}
     */
    haveLocalStorage: function () {
        return this.browserStorageAvailable("localStorage", true);
    },

    /**
     * Look up an item's value in a local or session storage and return it. If it is
     * stored as JSON then we parse it and return an object.
     *
     * @param {string} key the key to look up and return its respective value from the storage object indicated. The expectation
     * is you previously saved it with storageSave(key, value);
     * @param {Object} storageObject use either localStorage, sessionStorage, or null will default to 'localStorage'
     * @returns {string|*}
     */
    storageGet: function (key, storageObject) {
        if ( ! this.isBrowserEnvironment()) {
            return null;
        }
        let itemValueRaw;
        let itemValueParsed;

        if (storageObject === undefined || storageObject == null) {
            storageObject = window.localStorage;
        }
        itemValueRaw = storageObject.getItem(key);
        if (itemValueRaw != null) {
            itemValueParsed = JSON.parse(itemValueRaw);
            if (itemValueParsed == null) {
                itemValueParsed = itemValueRaw;
            }
        } else {
            itemValueParsed = null;
        }
        return itemValueParsed;
    },

    /**
     * Save an item in local storage. If the value is null, it will attempt to remove the item if it was
     * previously saved.
     * @param {string} key the key to store a respective value in the storage object indicated.
     * @param {any} object any data you want to store. Note Objects and Arrays are saved as JSON and loadObjectWithKey will
     * re-hydrate the object. Other types are converted to string so loadObjectWithKey will return a string.
     * @return {boolean} true if saved or removed. false for an error.
     */
    saveObjectWithKey: function (key, object) {
        var storageObject,
            itemValueRaw,
            saved = false;

        if (this.browserStorageAvailable("localStorage", false) && key != null) {
            try {
                storageObject = window.localStorage;
                if (object != null) {
                    if (typeof object === "object") {
                        itemValueRaw = JSON.stringify(object);
                    } else {
                        itemValueRaw = object.toString();
                    }
                    storageObject.setItem(key, itemValueRaw);
                } else {
                    storageObject.removeItem(key);
                }
                saved = true;
            } catch (exception) {
                saved = false;
            }
        }
        return saved;
    },

    /**
     * Return object from local storage that was saved with saveObjectWithKey.
     * @param {string} key The key property name to look up.
     * @returns {any} object that was saved with saveObjectWithKey().
     */
    loadObjectWithKey: function (key) {
        var maybeJsonData,
            storageObject,
            object = null;

        if (this.browserStorageAvailable("localStorage", false) && key != null) {
            try {
                storageObject = window.localStorage;
                maybeJsonData = storageObject[key];
                if (maybeJsonData != null) {
                    if (maybeJsonData[0] == "{" || maybeJsonData[0] == "]") {
                        object = JSON.parse(maybeJsonData);
                    } else {
                        object = maybeJsonData;
                    }
                }
            } catch (exception) {
                object = null;
            }
        }
        return object;
    },

    /**
     * Remove the given key from local storage.
     * @param {string} key Storage key to remove.
     */
    removeObjectWithKey: function (key) {
        var removed = false;

        if (this.browserStorageAvailable("localStorage", false) && key != null) {
            try {
                window.localStorage.removeItem(key);
                removed = true;
            } catch (exception) {
                removed = false;
            }
        }
        return removed;
    },

    /* ----------------------------------------------------------------------------------
     * Very basic social network sharing utilities
     * ----------------------------------------------------------------------------------*/

    shareOnFacebook: function (summary, url) {
        let shareMessage = encodeURIComponent(url);
        if (summary && summary != "") {
            shareMessage += "&quote=" + encodeURIComponent(summary);
        }
        window.open(
            "https://www.facebook.com/sharer/sharer.php?u=" + shareMessage,
            "_share",
            "toolbar=no,status=0,width=626,height=436"
        );
    },

    shareOnTwitter: function (message, url, related, hashTags) {
        let shareMessage = "text=" + encodeURIComponent(message);
        if (url && url != "") {
            shareMessage += "&url=" + encodeURIComponent(url);
        }
        if (related && related != "") {
            shareMessage += "&related=" + related;
        }
        if (hashTags && hashTags != "") {
            shareMessage += "&hashtags=" + hashTags;
        }
        window.open(
            "https://twitter.com/intent/tweet?" + shareMessage,
            "_share",
            "toolbar=no,status=0,width=626,height=436"
        );
    },

    /**
     * Share a user message with Bluesky.
     * @param {string} message User's share message text. This is required.
     * @param {string} url Optional URL to link to.
     * @param {string} hashTags Optional hash tags to include in the share message. This should be a string "#tag1 #tag2".
     */
    shareOnBsky: function (message, url, hashTags) {
        let shareMessage = "text=" + encodeURIComponent(message);
        if (url && url != "" && ! message.includes(url.toLowerCase())) {
            shareMessage += " " + encodeURIComponent(url);
        }
        if (hashTags && hashTags != "") {
            shareMessage += " " + encodeURIComponent(hashTags);
        }
        window.open(
            "https://bsky.app/intent/compose?" + shareMessage,
            "_share",
            "toolbar=no,status=0,width=626,height=436"
        );
    },

    shareByEmail: function (title, message, url) {
        let shareMessage;
        if (url && url != "") {
            shareMessage = message + "\n\n" + url;
        } else {
            shareMessage = message;
        }
        window.open(
            "mailto:?subject=" + encodeURIComponent(title) + "&body=" + encodeURIComponent(shareMessage),
            "_share",
            "popup=1,toolbar=no,status=0,noopener=1,noreferrer=1,width=626,height=436"
        );
    },

    shareBySMS: function (message, url) {
        let shareMessage;
        if (url && url != "") {
            shareMessage = message + ": " + url;
        } else {
            shareMessage = message;
        }
        window.open(
            "sms:?&body=" + encodeURIComponent(shareMessage),
            "_share",
            "popup=1,toolbar=no,status=0,noopener=1,noreferrer=1,width=626,height=436"
        );
    },

    /**
     * A very basic function performance tester. Will track the time it takes to run the
     *        function for the specified number of iterations.
     *
     * @param {function} testFunction a function to test. This function takes no parameters. If you
     *        require parameters then wrap into a function that takes no parameters.
     * @param {string} testId any id you want to assign to the test. Not used, but returned.
     * @param {integer} totalIterations number of times to call this function.
     * @return {object} test results object including test number, test function id, duration,
     *         duration units, and total iterations.
     */
    performanceTest: function (testFunction, testId, totalIterations) {
        if ( ! this.isBrowserEnvironment()) {
            return null;
        }
        let start;
        let duration;
        let i;
        let results;

        if (window.performance) {
            this._testNumber ++;
            start = window.performance.now();
            for (i = 0; i < totalIterations; i ++) {
                testFunction();
            }
            duration = window.performance.now() - start;
            results = {
                testNumber: this._testNumber,
                testFunction: testId,
                duration: duration,
                durationUnits: "ms",
                totalIterations: i
            };
        } else {
            results = null;
        }
        return results;
    },

    /**
     * Compare two semantic versions to determine if they are equal, or one is greater
     * than the other. This treats each version component as an integer, so that
     * leading 0's are not considered. 1.02.03 is the same version as 1.2.3.
     * @param {String} version1 A version string, 1.2.3 is expected.
     * @param {String} version2 A version string, same format as the first parameter.
     * @returns {integer} Result of the compare, 0 they are equal, 1 if first is less than second, -1 if first is greater than second.
     */
    simpleVersionCompare: function(version1, version2) {
        const v1parts = version1.split(".");
        const v2parts = version2.split(".");
        let first;
        let second;
        let result = 0;
        if (v1parts.length > v2parts.length) {
            first = v2parts;
            second = v1parts;
        } else {
            first = v1parts;
            second = v2parts;
        }
        for (let i = 0; i < first.length; i += 1) {
            const v1 = parseInt(first[i]);
            const v2 = parseInt(second[i]);
            if (v1 < v2) {
                result = 1;
                break;
            } else if (v1 > v2) {
                result = -1;
                break;
            }
        }
        return result;
    },

    /**
     * Convert a date into a MySQL compatible date string (YYYY-MM-DD).
     * If the date provided is a string we will attempt to convert it to a date object using the available
     * Date() constructor. If no date is provided we will use the current date. If none of these conditions
     * then we expect the date provided to be a valid Date object.
     * @param {null|string|Date} date one of null, a string, or a Date object
     * @returns {string} In the form YYYY-MM-DD
     */
    MySQLDate: function (date) {
        let dateToConvert;
        if (date == undefined || date == null) {
            dateToConvert = new Date();
        } else if (! (date instanceof Date)) {
            dateToConvert = new Date(date);
        } else {
            dateToConvert = date;
        }
        return dateToConvert.toISOString().slice(0, 10);
    },

    /**
     * Return the date it was years from today.
     * @param {integer} years Number of years before today.
     * @returns {Date}
     */
    subtractYearsFromNow: function (years) {
        var date = new Date();
        date.setFullYear(date.getFullYear() - years);
        return date;
    },

    /**
     * Inserts a new script element into the DOM on the indicated tag.
     *
     * @param {string} id The id attribute, so that the script element can be referenced.
     * @param {string} src The src attribute, usually a file reference or URL to a script to load.
     * @param {string} tagName optional tag you want to insert this script to. Defaults to "body"
     * @param {string} scriptType optional script type. Defaults to "JavaScript"
     * @returns {Boolean} true if inserted, false if error.
     */
    insertScriptElement: function (id, src, tagName, scriptType) {
        if ( ! document || document.getElementById(id)) {
            // no DOM or script already exists.
            return false;
        }
        if (this.isEmpty(tagName)) {
            tagName = "body";
        }
        if (this.isEmpty(scriptType)) {
            scriptType = "text/javascript";
        }
        let firstJSTag = document.getElementsByTagName(tagName)[0];
        if (firstJSTag == null) {
            firstJSTag = document.getElementsByTagName("div")[0];
        }
        const scriptElement = document.createElement("script");
        scriptElement.id = id;
        scriptElement.src = src;
        scriptElement.type = scriptType;
        scriptElement.async = true;
        firstJSTag.appendChild(scriptElement);
        return true;
    },

    /**
     * Parse a string of tags into individual tags array, making sure each tag is properly formatted.
     * A tag must be at least 1 character and no more than 50, without any leading or trailing whitespace,
     * and without any HTML tags (entities should be OK.)
     * @param {string} tags string of delimited tags.
     * @param {string} delimiter how the tags are separated, default is ;.
     * @returns {Array} array of individual tag strings, or empty array if nothing to parse or an error occurred.
     */
    tagParse: function (tags, delimiter) {
        let tagList;
        let i;

        if (typeof tags === "undefined" || tags === null || tags.length < 1) {
            tagList = [];
        } else {
            if (typeof delimiter === "undefined" || delimiter === null || delimiter == "") {
                delimiter = ";";
            }
            tagList = tags.split(delimiter);
            for (i = tagList.length - 1; i >= 0; i --) {
                tagList[i] = this.stripTags(tagList[i], "").substring(0, 50).trim();
                if (tagList[i].length < 1) {
                    tagList.splice(i, 1);
                }
            }
        }
        return tagList;
    },

    /**
     * Strip HTML tags from a string. Credit to http://locutus.io/php/strings/strip_tags/
     * @param {string} input input string to clean.
     * @param {string} allowed list of tags to accept.
     * @returns {string} the stripped result.
     */
    stripTags: function (input, allowed) {
        if (this.isNull(input)) {
            return "";
        } else if (typeof input !== "string") {
            input = input.toString();
        }
        if (this.isEmpty(input)) {
            return "";
        }
        allowed = (((allowed || "") + "").toLowerCase().match(/<[a-z][a-z0-9]*>/g) || []).join("");
        const tags = /<\/?([a-z][a-z0-9]*)\b[^>]*>/gi;
        const commentsAndPhpTags = /<!--[\s\S]*?-->|<\?(?:php)?[\s\S]*?\?>/gi;
        return input.replace(commentsAndPhpTags, "").replace(tags, function ($0, $1) {
            return allowed.indexOf("<" + $1.toLowerCase() + ">") > -1 ? $0 : ""
        });
    },

    /**
     * Determine if a string looks like a valid email address. This is a simple sanity test,
     * must be in the form of "something @ something . something".
     * Old version: return /^(([^<>()\[\]\.,;:\s@\"]+(\.[^<>()\[\]\.,;:\s@\"]+)*)|(\".+\"))@(([^<>()\.,;\s@\"]+\.{0,1})+([^<>()\.,;:\s@\"]{2,}|[\d\.]+))$/.test(email);
     * @param {string} email String to expect an email address
     * @returns {boolean} true if we think it is a valid email address.
     */
    isValidEmail: function(email) {
        return /\S+@\S+\.\S+/.test(email);
    },

    /**
     * Validate an array of fields, such as user form inputs, by using a matching array of
     * field definitions. The result is an array of fields that failed the validation and
     * the reason for failure. It is important to note the logic is driven from the
     * keyValueArrayOfDefinitions for-each key in that array the key/value is looked up
     * in keyValueArrayOfFields. This way missing fields are handled. Conversely, any
     * keys in keyValueArrayOfFields that do not appear in keyValueArrayOfDefinitions are
     * ignored.
     *
     * When using the date range check, all dates (min, max, and the value) must be JavaScript
     * date objects.
     *
     * @param {Array|Object} keyValueArrayOfFields A key-value array of fields to validate. The key
     *   is the name of the field. The value is the value assigned to that field that will be
     *   validated using the rules defined in keyValueArrayOfDefinitions.
     *
     * @param {Array|Object} keyValueArrayOfDefinitions A key-value array of field rules where the
     *   key must match the field key in keyValueArrayOfFields. The value of that key is the
     *   set of rules. The rule set itself is defined as a key/value array of mandatory and
     *   optional keys, as follows:
     *   type: string defining the data type expected. Optional, the default is "string".
     *         Valid types are string, number, bool, array, date, email.
     *   optional: boolean indicates if the field value is optional. When true, the key
     *         does not have to exist in keyValueArrayOfFields. If it does exist we accept
     *         no value for the field (null, "", or any valid empty value.) If it does
     *         exist and it is not empty it must then pass the validation test. When false
     *         the key must exist and pass the validation test. Default is false.
     *   min: The minimum value for the field. For strings this is the minimum length. For
     *         dates the earliest date. For sets the minimum number of items. Does not
     *         apply to bool. Default is - infinity.
     *   max: The maximum value for the field. For strings this is the maximum length. For
     *         dates the latest date. For sets the maximum number of items. Does not
     *         apply to bool. Default is infinity.
     *   options: an array of allowed values. Optional, default is empty.
     *   validator: A function you can pass to perform the validation. This function takes
     *         two arguments, the field name and the field value. It must return true if
     *         the value is valid and false if the value is invalid.
     * @return {Array} A key/value array of fields that failed their test. when empty, all
     *   tests passed. When not empty, each key in this array is the field name key.
     *   The value is an object constructed as follows:
     *   code: integer An error code, can be used to look up an error in a string table.
     *   message: string the error message.
     */
    validateFields: function (keyValueArrayOfFields, keyValueArrayOfDefinitions) {
        var result = [],
            field,
            fieldDefinition,
            fieldValue,
            fieldTime,
            options,
            i;

        if (keyValueArrayOfFields != null && keyValueArrayOfDefinitions != null) {
            for (field in keyValueArrayOfDefinitions) {
                if (keyValueArrayOfDefinitions.hasOwnProperty(field)) {
                    fieldDefinition = keyValueArrayOfDefinitions[field];
                    fieldValue = keyValueArrayOfFields[field];
                    if ( ! fieldDefinition.hasOwnProperty("optional")) {
                        fieldDefinition.optional = false;
                    }
                    if ( ! fieldDefinition.optional && this.isEmpty(fieldValue)) {
                        result[field] = {code: "required", message: "This field is required."};
                    } else if (fieldDefinition.hasOwnProperty("validator")) {
                        if ( ! fieldDefinition.validator(field, fieldValue)) {
                            result[field] = {code: "validator", message: "This field failed validation."};
                        }
                    } else if ( ! (fieldDefinition.optional && this.isEmpty(fieldValue))) {
                        if ( ! fieldDefinition.hasOwnProperty("type")) {
                            fieldDefinition.type = "string";
                        }
                        if ( ! fieldDefinition.hasOwnProperty("min")) {
                            fieldDefinition.min = fieldDefinition.type == "number" ? Number.MIN_SAFE_INTEGER : 0;
                        }
                        if ( ! fieldDefinition.hasOwnProperty("max")) {
                            fieldDefinition.max = Number.MAX_SAFE_INTEGER;
                        }
                        if (fieldDefinition.hasOwnProperty("options")) {
                            options = fieldDefinition.options;
                        } else {
                            options = [];
                        }
                        switch (fieldDefinition.type) {
                            case "string":
                                if (fieldValue.length < fieldDefinition.min) {
                                    result[field] = {code: "min", message: "The field length is less than the minimum number of characters."};
                                } else if (fieldValue.length > fieldDefinition.max) {
                                    result[field] = {code: "max", message: "The field length is more than the maximum number of characters."};
                                } else if (options.length > 0) {
                                    if (options.indexOf(fieldValue) < 0) {
                                        result[field] = {code: "options", message: "The field value is not an option."};
                                    }
                                }
                                break;
                            case "number":
                                if (fieldValue < fieldDefinition.min) {
                                    result[field] = {code: "min", message: "The field is less than the minimum value allowed."};
                                } else if (fieldValue > fieldDefinition.max) {
                                    result[field] = {code: "max", message: "The field is more than the maximum value allowed."};
                                } else if (options.length > 0) {
                                    if (options.indexOf(fieldValue) < 0) {
                                        result[field] = {code: "options", message: "The field value is not an option."};
                                    }
                                }
                                break;
                            case "bool":
                            case "boolean":
                                if (options.length > 0) {
                                    if (options.indexOf(fieldValue) < 0) {
                                        result[field] = {code: "options", message: "The field value is not an option."};
                                    }
                                }
                                break;
                            case "date":
                                if (fieldValue instanceof Date) {
                                    fieldTime = fieldValue.getTime();
                                    if (fieldTime < fieldDefinition.min) {
                                        result[field] = {code: "min", message: "The date field is before the minimum date allowed."};
                                    } else if (fieldTime > fieldDefinition.max) {
                                        result[field] = {code: "max", message: "The date field is after the maximum date allowed."};
                                    } else if (options.length > 0) {
                                        if (options.indexOf(fieldTime) < 0) {
                                            result[field] = {code: "options", message: "The field value is not a valid option."};
                                        }
                                    }
                                }
                                break;
                            case "array":
                                if (fieldValue.length < fieldDefinition.min) {
                                    result[field] = {code: "min", message: "The field contains less than the minimum number of items."};
                                } else if (fieldValue.length > fieldDefinition.max) {
                                    result[field] = {code: "max", message: "The field contains more than the maximum number of items."};
                                } else if (options.length > 0) {
                                    for (i = 0; i < fieldValue.length; i ++) {
                                        if (options.indexOf(fieldValue[i]) < 0) {
                                            result[field] = {code: "options", message: "A field value is not a valid option."};
                                            break;
                                        }
                                    }
                                }
                                break;
                            case "email":
                                if ( ! this.isEmpty(fieldValue) && ! this.isValidEmail(fieldValue)) {
                                    result[field] = {code: "invalid", message: "The email address is not valid."};
                                }
                                break;
                        }
                    }
                }
            }
        }
        return result;
    },

    /**
     * Parse a domain or a URL to return the domain with the server dropped.
     * Works on either a domain name (e.g. www.host.com) or a URL (e.g.
     * https://www.host.com/path). In either case this function should return
     * the domain the server is a member of, e.g. `host.com`.
     *
     * @param {String} proposedHost A proposed URL or domain name to parse.
     * @returns {String} The proposed host domain with the server removed.
     */
    domainDropServer: function(proposedHost) {
        var targetHost = proposedHost ? proposedHost.toString() : "";
        var pos = targetHost.indexOf("://"); // remove the protocol
        if (pos > 0) {
            targetHost = targetHost.substring(pos + 3);
        }
        pos = targetHost.indexOf("//"); // remove the neutral protocol
        if (pos == 0) {
            targetHost = targetHost.substring(2);
        }
        pos = targetHost.indexOf("/"); // remove everything after the domain
        if (pos > 0) {
            targetHost = targetHost.substring(0, pos);
        }
        pos = targetHost.indexOf(":"); // remove everything after the port
        if (pos > 0) {
            targetHost = targetHost.substring(0, pos);
        }
        var domainParts = targetHost.split(".");
        if (domainParts.length > 2) {
            domainParts.shift();
        }
        targetHost = domainParts.join(".")
        return targetHost;
    },

    /**
     * Encrypt a string of data using the AES CBC algorithm. This is an asynchronous function
     * that returns a promise that will resolve with the encrypted data encoded in base-64,
     * or an exception. Failures are usually due to incorrect key format. The key and iv provided
     * to `encryptString` must be the exact same data provided to `decryptString`.
     * @param {string} data String of data to encrypt.
     * @param {string} key Key must be hex digits represented as string "0123456789abcdef" at least 32 chars in length.
     * @param {string} iv Initialization vector is a 16 byte string.
     * @return {Promise} A Promise that will resolve with a Base-64 encoded encrypted data.
     */
    encryptString: async function(data, key, iv) {
        const context = this;
        return new Promise(function(resolve, reject) {
            const encryptMethod = "AES-CBC";
            crypto.subtle.importKey(
                "raw",
                context.stringToByteArray(key),
                {
                    name: encryptMethod
                },
                true,
                ["encrypt", "decrypt"]
            ).then(function(cryptoKey) {
                const encoder = new TextEncoder();
                crypto.subtle.encrypt(
                    {
                        name: encryptMethod,
                        iv: iv,
                    },
                    cryptoKey,
                    encoder.encode(data)
                )
                .then(function(cipherData) {
                    resolve(context.arrayBufferToBase64(cipherData));
                })
                .catch(function(exception) {
                    reject(exception);
                });
            })
            .catch(function(exception) {
                reject(exception);
            });
        });
    },

    /**
     * Decrypt a string that was encrypted with `encryptString()` and the matching key and iv.
     * @param {string} encryptedData String of base-64 encoded data that was encrypted with key.
     * @param {string} key Key must be hex digits represented as string "0123456789abcdef".
     * @param {string} iv Initialization vector is a 16 byte string.
     * @return {string} Original data.
     */
    decryptString: async function(encryptedData, key, iv) {
        const context = this;
        return new Promise(function(resolve, reject) {
            const encryptMethod = "AES-CBC";
            crypto.subtle.importKey(
                "raw",
                context.stringToByteArray(key),
                {
                    name: encryptMethod
                },
                true,
                ["encrypt", "decrypt"]
            ).then(function(cryptoKey) {
                crypto.subtle.decrypt(
                    {
                        name: encryptMethod,
                        iv: iv,
                    },
                    cryptoKey,
                    context.base64ToArrayBuffer(encryptedData)
                )
                .then(function(decryptedData) {
                    const decoder = new TextDecoder();
                    const clearData = decoder.decode(decryptedData);
                    resolve(clearData);
                })
                .catch(function(exception) {
                    reject(exception);
                });
            })
            .catch(function(exception) {
                reject(exception);
            });
        });
    },

    /**
     * Compute MD5 checksum for the given string.
     * @param {string} s String to hash.
     * @returns {string} MD5 checksum.
     */
    md5: function (s) {
        function L(k,d) {
            return(k<<d)|(k>>>(32-d))
        }
        function K(G,k) {
            var I,d,F,H,x;
            F=(G&2147483648);H=(k&2147483648);I=(G&1073741824);d=(k&1073741824);x=(G&1073741823)+(k&1073741823);
            if(I&d){return(x^2147483648^F^H);}
            if(I|d){if(x&1073741824){return(x^3221225472^F^H);}else{return(x^1073741824^F^H);}}else{return(x^F^H);}
        }
        function r(d,F,k){
            return(d&F)|((~d)&k);
        }
        function q(d,F,k){
            return(d&k)|(F&(~k));
        }
        function p(d,F,k){return(d^F^k)}
        function n(d,F,k){return(F^(d|(~k)))}
        function u(G,F,aa,Z,k,H,I){G=K(G,K(K(r(F,aa,Z),k),I));return K(L(G,H),F)}
        function f(G,F,aa,Z,k,H,I){G=K(G,K(K(q(F,aa,Z),k),I));return K(L(G,H),F)}
        function D(G,F,aa,Z,k,H,I){G=K(G,K(K(p(F,aa,Z),k),I));return K(L(G,H),F)}
        function t(G,F,aa,Z,k,H,I){G=K(G,K(K(n(F,aa,Z),k),I));return K(L(G,H),F)}
        function e(G){
            var Z;var F=G.length;var x=F+8;var k=(x-(x%64))/64;var I=(k+1)*16;var aa=Array(I-1);var d=0;var H=0;
            while(H<F){
                Z=(H-(H%4))/4;d=(H%4)*8;aa[Z]=(aa[Z]|(G.charCodeAt(H)<<d));H++;
            }
            Z=(H-(H%4))/4;d=(H%4)*8;aa[Z]=aa[Z]|(128<<d);aa[I-2]=F<<3;aa[I-1]=F>>>29;
            return aa;
        }
        function B(x){
            var k="",F="",G,d;
            for(d=0;d<=3;d++){
                G=(x>>>(d*8))&255;F="0"+G.toString(16);k=k+F.substr(F.length-2,2);
            }
            return k;
        }
        function J(k){
            k=k.replace(/rn/g,"n");var d="";
            for(var F=0;F<k.length;F++){
                var x=k.charCodeAt(F);
                if(x<128){
                    d+=String.fromCharCode(x);
                }else{
                    if((x>127)&&(x<2048)){
                        d+=String.fromCharCode((x>>6)|192);d+=String.fromCharCode((x&63)|128);
                    }else{
                        d+=String.fromCharCode((x>>12)|224);d+=String.fromCharCode(((x>>6)&63)|128);d+=String.fromCharCode((x&63)|128);
                    }
                }
            }
            return d;
        }
        var C;var P,h,E,v,g,Y,X,W,V;var S=7,Q=12,N=17,M=22;var A=5,z=9,y=14,w=20;var o=4,m=11,l=16,j=23;var U=6,T=10,R=15,O=21;
        s=J(s);C=e(s);Y=1732584193;X=4023233417;W=2562383102;V=271733878;
        for (P=0;P<C.length;P+=16){
            h=Y;E=X;v=W;g=V;Y=u(Y,X,W,V,C[P+0],S,3614090360);V=u(V,Y,X,W,C[P+1],Q,3905402710);W=u(W,V,Y,X,C[P+2],N,606105819);X=u(X,W,V,Y,C[P+3],M,3250441966);Y=u(Y,X,W,V,C[P+4],S,4118548399);V=u(V,Y,X,W,C[P+5],Q,1200080426);W=u(W,V,Y,X,C[P+6],N,2821735955);X=u(X,W,V,Y,C[P+7],M,4249261313);Y=u(Y,X,W,V,C[P+8],S,1770035416);V=u(V,Y,X,W,C[P+9],Q,2336552879);W=u(W,V,Y,X,C[P+10],N,4294925233);X=u(X,W,V,Y,C[P+11],M,2304563134);Y=u(Y,X,W,V,C[P+12],S,1804603682);V=u(V,Y,X,W,C[P+13],Q,4254626195);W=u(W,V,Y,X,C[P+14],N,2792965006);X=u(X,W,V,Y,C[P+15],M,1236535329);Y=f(Y,X,W,V,C[P+1],A,4129170786);V=f(V,Y,X,W,C[P+6],z,3225465664);W=f(W,V,Y,X,C[P+11],y,643717713);X=f(X,W,V,Y,C[P+0],w,3921069994);Y=f(Y,X,W,V,C[P+5],A,3593408605);V=f(V,Y,X,W,C[P+10],z,38016083);W=f(W,V,Y,X,C[P+15],y,3634488961);X=f(X,W,V,Y,C[P+4],w,3889429448);Y=f(Y,X,W,V,C[P+9],A,568446438);V=f(V,Y,X,W,C[P+14],z,3275163606);W=f(W,V,Y,X,C[P+3],y,4107603335);X=f(X,W,V,Y,C[P+8],w,1163531501);Y=f(Y,X,W,V,C[P+13],A,2850285829);V=f(V,Y,X,W,C[P+2],z,4243563512);W=f(W,V,Y,X,C[P+7],y,1735328473);X=f(X,W,V,Y,C[P+12],w,2368359562);Y=D(Y,X,W,V,C[P+5],o,4294588738);V=D(V,Y,X,W,C[P+8],m,2272392833);W=D(W,V,Y,X,C[P+11],l,1839030562);X=D(X,W,V,Y,C[P+14],j,4259657740);Y=D(Y,X,W,V,C[P+1],o,2763975236);V=D(V,Y,X,W,C[P+4],m,1272893353);W=D(W,V,Y,X,C[P+7],l,4139469664);X=D(X,W,V,Y,C[P+10],j,3200236656);Y=D(Y,X,W,V,C[P+13],o,681279174);V=D(V,Y,X,W,C[P+0],m,3936430074);W=D(W,V,Y,X,C[P+3],l,3572445317);X=D(X,W,V,Y,C[P+6],j,76029189);Y=D(Y,X,W,V,C[P+9],o,3654602809);V=D(V,Y,X,W,C[P+12],m,3873151461);W=D(W,V,Y,X,C[P+15],l,530742520);X=D(X,W,V,Y,C[P+2],j,3299628645);Y=t(Y,X,W,V,C[P+0],U,4096336452);V=t(V,Y,X,W,C[P+7],T,1126891415);W=t(W,V,Y,X,C[P+14],R,2878612391);X=t(X,W,V,Y,C[P+5],O,4237533241);Y=t(Y,X,W,V,C[P+12],U,1700485571);V=t(V,Y,X,W,C[P+3],T,2399980690);W=t(W,V,Y,X,C[P+10],R,4293915773);X=t(X,W,V,Y,C[P+1],O,2240044497);Y=t(Y,X,W,V,C[P+8],U,1873313359);V=t(V,Y,X,W,C[P+15],T,4264355552);W=t(W,V,Y,X,C[P+6],R,2734768916);X=t(X,W,V,Y,C[P+13],O,1309151649);Y=t(Y,X,W,V,C[P+4],U,4149444226);V=t(V,Y,X,W,C[P+11],T,3174756917);W=t(W,V,Y,X,C[P+2],R,718787259);X=t(X,W,V,Y,C[P+9],O,3951481745);Y=K(Y,h);X=K(X,E);W=K(W,v);V=K(V,g);
        }
        var i=B(Y)+B(X)+B(W)+B(V);
        return i.toLowerCase();
    },

    /**
     * Given a user email, generate the Gravatar URL for the image.
     * @param {string} email An email address. This is not validated.
     * @param {integer} size THe size of the avatar image to return, width and height are equal.
     * @returns {string} - A URL.
     */
    getGravatarURL: function (email, size) {
        var size = size || 80;
        return "https://www.gravatar.com/avatar/" + this.md5(email) + ".jpg?s=" + size;
    }
}
