/**
 * @module: enginesis - JavaScript interface for Enginesis SDK
 * @author: jf, Varyn, Inc.
 * @since: 7/25/13
 * @summary: A JavaScript interface to the Enginesis API. This is designed to be a singleton
 *  object, only one should ever exist. It represents the data model and service/event model
 *  to converse with the server, and provides an overridable callback function to get the server response.
 *  This is also only intended to be a browser-based client library and expects a window object
 *  to be available.
 * @exports enginesis
 **/

/**
 * Construct the singleton Enginesis object with initial parameters. Call `init` before any other function.
 * * @returns {object} Enginesis object to perform operations on.
 */
(function enginesis (global) {
    "use strict";

    var enginesis = {
        VERSION: "2.6.17",
        debugging: true,
        disabled: false, // use this flag to turn off communicating with the server
        isOnline: true,  // flag to determine if we are currently able to reach Enginesis servers
        isPaused: false, // flag to track if the game is paused.
        errorLevel: 15,  // bitmask: 1=info, 2=warning, 4=error, 8=severe
        useHTTPS: true,
        serverStage: null,
        serverHost: null,
        siteResources: {
            serviceURL: null,
            avatarImageURL: null,
            assetUploadURL: null
        },
        siteId: 0,
        gameId: 0,
        gameKey: "",
        gameGroupId: 0,
        languageCode: "en",
        internalStateSeq: 0,
        lastResponse: null,
        callBackFunction: null,
        authToken: null,
        authTokenWasValidated: false,
        authTokenExpires: null,
        refreshToken: null,
        refreshTokenExpires: null,
        sessionId: null,
        sessionExpires: null,
        developerKey: null,
        loggedInUserInfo: {},
        networkId: 1,
        platform: "",
        locale: "en-US",
        isUserLoggedIn: false,
        isNativeBuild: false,
        isBrowserBuild: typeof window !== "undefined" && window !== null && typeof window.document !== "undefined" && typeof window.location !== "undefined",
        isNodeBuild: typeof process !== "undefined" && process.versions != null && process.versions.node != null,
        isTouchDeviceFlag: false,
        SESSION_COOKIE: "engsession",
        SESSION_USERINFO: "engsession_user",
        refreshTokenStorageKey: "engrefreshtoken",
        captchaId: "99999",
        captchaResponse: "DEADMAN",
        anonymousUserKey: "enginesisAnonymousUser",
        anonymousUser: null,
        serviceQueue: [],
        serviceQueueSaveKey: "enginesisServiceQueue",
        serviceQueueRestored: 0,
        assetUploadQueue: null,
        nodeRequest: null,
        gameInfo: null,
        favoriteGames: new Set(),
        favoriteGamesNextCheck: 0,
        supportedNetworks: {
            Enginesis: 1,
            Facebook:  2,
            Google:    7,
            Twitter:  11,
            Apple:    14
        }
    };
    let enginesisContext = null;

    /**
     * Internal logging function. All logging should call this function to abstract and control the interface.
     * @param {string} message A message to show in the log.
     * @param {integer} level Message is sent to log only if this level is turned on.
     */
    function debugLog(message, level) {
        if (enginesis.debugging) {
            if (level == null) {
                level = 15;
            }
            if ((enginesis.errorLevel & level) > 0) {
                // only show this message if the error level is on for the level we are watching
                console.log(message);
            }
            if (level == 9) {
                alert(message);
            }
        }
    }

    /**
     * Review the current state of the enginesis object to make sure we have enough information
     * to properly communicate with the server. The decision may change over time, but for now Enginesis requires:
     *   1. Developer key - the developer's API key is required to make API calls.
     *   2. Site id - we must know the site id to make any API calls and to verify the developer key matches.
     *   3. serviceURL - must be set in order to make API calls.
     * @returns {boolean} true if we think we are in a good state, otherwise false.
     */
    function isValidOperationalState() {
        return enginesis.siteId > 0 && enginesis.developerKey.length > 0 && enginesis.siteResources.serviceURL.length > 0;
    }

    /**
     * Determine if a given variable is considered an empty value. A value is considered empty if it is any one of
     * `null`, `undefined`, `false`, `NaN`, an empty string, an empty array, or 0. Note this does not consider an
     * empty object `{}` to be empty.
     * @param {any} value The parameter to be tested for emptiness.
     * @returns {boolean} `true` if the value is considered empty.
     */
    function isEmpty (value) {
        return value === undefined
        || value === null
        || value === false
        || (typeof value === "string" && (value === "" || value === "undefined"))
        || (Array.isArray(value) && value.length == 0)
        || (typeof value === "number" && (isNaN(value) || value === 0));
    }

    /**
     * Determine if a given variable is considered null (either null or undefined).
     * At the moment this will not check for "null"/"NULL" values, as when using SQL.
     * @param {any} field A value to consider.
     * @returns {boolean} `true` if `value` is considered null.
     */
    function isNull (field) {
        return field === undefined || field === null;
    }

    /**
     * Coerce a value to its boolean equivalent, causing the value to be interpreted as its
     * boolean intention. This works very different that the JavaScript coercion. For example,
     * "0" == true and "false" == true in JavaScript but here "0" == false and "false" == false.
     * @param {any} value A value to test.
     * @returns {boolean} `true` if `value` is considered a coercible true value.
     */
    function coerceBoolean (value) {
        if (typeof value === "string") {
            value = value.toLowerCase();
            return value === "1" || value === "true" || value === "t" || value === "checked" || value === "yes" || value === "y";
        } else {
            return value === true || value === 1;
        }
    }

    /**
     * Coerce a value to the first non-empty value of a given set of parameters. It is expected the last
     * parameter is a non-empty value and is the expected result when all arguments are empty values. If
     * for some reason this function is called with an unexpected number of parameters it returns `null`.
     * See `isEmpty()` for the meaning of "empty".
     * @param {any} arguments Any number of parameters, at least the last one is expected to be not empty.
     * @returns {any} The first parameter encountered, in order, that is not an empty value.
     */
    function coerceNotEmpty() {
        var result;
        var numberOfArguments = arguments.length;
        if (numberOfArguments == 0) {
            result = null;
        } else if (numberOfArguments == 1) {
            result = arguments[0];
        } else {
            for (var i = 0; i < numberOfArguments; i ++) {
                if ( ! isEmpty(arguments[i])) {
                    result = arguments[i];
                    break;
                }
            }
            if (result === undefined) {
                result = arguments[numberOfArguments - 1];
            }
        }
        return result;
    }

    /**
     * Coerce a value to the first non-null value of a given set of parameters. It is expected the last
     * parameter is a non-null value and is the expected result when all arguments are null values. If
     * for some reason this function is called with an unexpected number of parameters it returns `null`.
     * See `isNull()` for the meaning of "null".
     * @param {any} arguments Any number of parameters, at least the last one is expected to be not null.
     * @returns {any} The first parameter encountered, in order, that is not a null value.
     */
    function coerceNotNull() {
        var result;
        var numberOfArguments = arguments.length;
        if (numberOfArguments == 0) {
            result = null;
        } else if (numberOfArguments == 1) {
            result = arguments[0];
        } else {
            for (var i = 0; i < numberOfArguments; i ++) {
                if ( ! isNull(arguments[i])) {
                    result = arguments[i];
                    break;
                }
            }
            if (result === undefined) {
                result = arguments[numberOfArguments - 1];
            }
        }
        return result;
    }

    /**
     * Verify we only deal with valid genders. Valid genders are M, F, and N.
     * @param {string} gender A string identifying gender, one of [M|Male|F|Female]. Anything else is considered "Neutral/none/neither."
     * @returns {string} a single character, one of [M|F|N]
     * @todo: Consider language code.
     */
    function validGender(gender) {
        let properGender;
        if (isEmpty(gender)) {
            properGender = "N";
        } else {
            properGender = gender.trim().toUpperCase();
            if (properGender[0] == "M") {
                properGender = "M";
            } else if (properGender[0] == "F") {
                properGender = "F";
            } else {
                properGender = "N";
            }
        }
        return properGender;
    }

    /**
     * Save an object in local storage given a key.
     * @param {string} key Key to identify object. If this key exists it will be overwritten with `object`.
     * @param {object} object Value to save under key.
     */
    function saveObjectWithKey(key, object) {
        if (key != null && object != null && typeof window.localStorage !== "undefined") {
            window.localStorage[key] = JSON.stringify(object);
        }
    }

    /**
     * Delete a local storage key.
     * @param {string} key Key to identify object.
     */
    function removeObjectWithKey(key) {
        if (key != null && typeof window.localStorage !== "undefined") {
            window.localStorage.removeItem(key);
        }
    }

    /**
     * Restore an object previously saved in local storage.
     * @param {string} key A key to look up in local storage.
     * @returns {object} The data that was saved under key. If key was never previously saved then null is returned.
     */
    function loadObjectWithKey(key) {
        var jsonData,
            object = null;

        if (key != null && typeof window.localStorage !== "undefined") {
            jsonData = window.localStorage[key];
            if (jsonData != null) {
                object = JSON.parse(jsonData);
            }
        }
        return object;
    }

    /**
     * Return the status of an enginesis service request.
     * @param {object} enginesisResult Enginesis server result object.
     * @returns {boolean} true if the request succeeded (it may succeed but return no results) or false if the request failed.
     */
    function resultIsSuccess(enginesisResult) {
        return enginesisResult && enginesisResult.results && enginesisResult.results.status && enginesisResult.results.status.success == "1";
    }

    /**
     * Determine if the request failed because the users authentication has expired.
     * @param {object} enginesisResult Enginesis server result object.
     * @returns {boolean} true if the server response is the users token is expired.
     */
    function resultIsExpiredToken(enginesisResult) {
        return enginesisResult && enginesisResult.results && enginesisResult.results.status && enginesisResult.results.status.success == "0" && enginesisResult.results.status.message == "TOKEN_EXPIRED";
    }

    /**
     * When a service request fails due to an expired token, it may be possible to refresh
     * the users authentication and reissue the original request. This function attempts to do that by:
     *   1. Determine if we have the refresh token. if so, call SessionRefresh. if not, resolve with original result object.
     *   2. If SessionRefresh fails, resolve with original result object.
     *   3. If SessionRefresh succeeds, reissue the original request and resolve with its response.
     * @param {object} enginesisResult Enginesis server result object of the original request.
     * @returns {Promise} Resolves when session is refreshed and original request is complete, or resolves
     *   with any error that occurred in the process.
     */
    function refreshTokenAndReissueRequest(enginesisResult) {
        return new Promise(function(resolve) {
            if (_getRefreshToken() !== null) {
                enginesis.sessionRefresh(_getRefreshToken(), null)
                .then(function(sessionRefreshResult) {
                    debugLog("refreshTokenAndReissueRequest users authentication has been refreshed. " + sessionRefreshResult.toString());
                    // Reissue original request
                    var serviceName = enginesisResult.results.passthru.fn;
                    var parameters = enginesisResult.results.passthru;
                    sendRequest(serviceName, parameters, null)
                    .then(function(reissueResult) {
                        resolve(reissueResult);
                    }, function(enginesisError) {
                        debugLog("refreshTokenAndReissueRequest refresh error " + enginesisError.toString());
                        resolve(enginesisResult);
                    })
                    .catch(function(exception) {
                        debugLog("refreshTokenAndReissueRequest refresh exception " + exception.toString());
                        resolve(enginesisResult);
                    });
                }, function(enginesisError) {
                    debugLog("refreshTokenAndReissueRequest refresh error " + enginesisError.toString());
                    resolve(enginesisResult);
                })
                .catch(function(exception) {
                    debugLog("refreshTokenAndReissueRequest refresh exception " + exception.toString());
                    resolve(enginesisResult);
                });
            } else {
                // We cannot refresh the token so respond with the original error.
                resolve(enginesisResult);
            }
        });
    }

    /**
     * Return the error code associated with an enginesis service request. Successful requests
     * usually return an empty string for the error code.
     * @param {object} enginesisResult Enginesis server result object.
     * @returns {string} An enginesis error code (look it up in the error code table.)
     */
    function resultErrorCode(enginesisResult) {
        if (enginesisResult && enginesisResult.results && enginesisResult.results.status) {
            return enginesisResult.results.status.message;
        } else {
            return "INVALID_PARAMETER";
        }
    }

    /**
     * Generate a standard Enginesis error response for situations where we identified an error condition
     * internally in the SDK and want to reply with a standard response. Complement to PHP function makeErrorResponse().
     * @param {string} errorCode EnginesisErrors error code.
     * @param {string} errorMessage Extended error information.
     * @param {Object|Array} passthruParameters Key/value parameters to include in passthru
     * @returns {Object} An EnginesisResponse object.
     */
    function makeErrorResponse(errorCode, errorMessage, passthruParameters) {
        const passthru = {...{state_seq: 0, fn: "unknown"}, ...passthruParameters};
        return {
            fn: passthru.fn,
            results: {
                passthru: passthru,
                result: [],
                status: {
                    success: (errorCode == "" || errorCode == "NO_ERROR") ? "1" : "0",
                    message: errorCode,
                    extended_info: errorMessage
                }    
            }
        };
    }

    /**
     * Internal function to handle completed service request and convert the JSON response to
     * an object and then invoke the call back function.
     * @param {Number} stateSequenceNumber The state identifier corresponding to this transaction.
     * @param {EnginesisResponse} enginesisResponseData The Enginesis response object. This is either
     *   a JSON string returned from the server or the JSON parsed object.
     * @param {Function} overRideCallBackFunction Optional function to call when complete.
     */
    function serviceRequestComplete (stateSequenceNumber, enginesisResponseData, overRideCallBackFunction) {
        let enginesisResponseObject;

        removeFromServiceQueue(stateSequenceNumber);
        try {
            if (typeof enginesisResponseData === "string") {
                enginesisResponseObject = JSON.parse(enginesisResponseData);
            } else {
                enginesisResponseObject = enginesisResponseData;
            }
        } catch (exception) {
            enginesisResponseObject = forceErrorResponseObject("unknown", stateSequenceNumber, "SERVICE_ERROR", "Error: " + exception.message + "; " + enginesisResponseData.toString(), {});
            debugLog("Enginesis requestComplete exception " + JSON.stringify(enginesisResponseObject));
        }
        enginesisResponseObject.fn = enginesisResponseObject.results.passthru.fn;
        enginesis.lastResponse = enginesisResponseObject;
        if (typeof overRideCallBackFunction == "function") {
            overRideCallBackFunction(enginesisResponseObject);
        } else if (typeof enginesis.callBackFunction == "function") {
            enginesis.callBackFunction(enginesisResponseObject);
        }
    }

    /**
     * When the server responds, intercept any result we get so we can preprocess it before
     * sending it off to the callback function. This may require different logic for different
     * services. In most cases a service call will result in some form of internal state update,
     * such as a refresh auth token or updated game info. In some cases, the server responds with
     * an error that we can resolve with further service calls.
     * @param {Object} enginesisResult Enginesis result object from the service response.
     * @returns {Promise} Resolves with an enginesisResult object when the result pre-process is complete.
     */
    function preprocessEnginesisResult(enginesisResult) {
        return new Promise(function(resolve, reject) {
            var serviceEndPoint = enginesisResult.fn;
            // Handle an expired token here, issue a SessionRefresh, and then re-issue the original request
            if (resultIsExpiredToken(enginesisResult)) {
                refreshTokenAndReissueRequest(enginesisResult)
                .then(function(reissueResult) {
                    resolve(reissueResult);
                });
            } else if (resultIsSuccess(enginesisResult) && serviceEndPoint) {
                // @todo: find a better place to define this dispatch table
                var dispatchTable = {
                    SessionBegin: updateGameSessionInfo,
                    SessionRefresh: refreshSessionInfo,
                    UserLogin: updateLoggedInUserInfo,
                    UserLogout: clearLoggedInUserInfo,
                    GameGet: updateGameInfo,
                    UserFavoriteGamesList: updateFavoriteGames,
                    UserFavoriteGamesAssign: updateFavoriteGames,
                    UserFavoriteGamesAssignList: updateFavoriteGames,
                    UserFavoriteGamesUnassign: updateFavoriteGames,
                    UserFavoriteGamesUnassignList: updateFavoriteGames
                };
                var dispatchFunction = dispatchTable[serviceEndPoint];
                if ( ! isNull(dispatchFunction)) {
                    dispatchFunction(enginesisResult);
                }
            }
            resolve(enginesisResult);
        });
    }

    /**
    * Compute the MD5 checksum for the given string.
    * @param {string} s String/byte array to compute the checksum.
    * @returns {string} MD5 checksum.
    */
    function md5 (s) {
        function L(k,d) { return(k<<d)|(k>>>(32-d)) }
        function K(G,k) {
            var I,d,F,H,x;
            F=(G&2147483648);H=(k&2147483648);I=(G&1073741824);d=(k&1073741824);x=(G&1073741823)+(k&1073741823);
            if(I&d){return(x^2147483648^F^H);}
            if(I|d){if(x&1073741824){return(x^3221225472^F^H);}else{return(x^1073741824^F^H);}}else{return(x^F^H);}
        }
        function r(d,F,k){ return(d&F)|((~d)&k); }
        function q(d,F,k){ return(d&k)|(F&(~k)); }
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
                G=(x>>>(d*8))&255;F="0"+G.toString(16);k=k+F.substring(F.length-2);
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
        var i,C,P,h,E,v,g,Y,X,W,V,S=7,Q=12,N=17,M=22,A=5,z=9,y=14,w=20,o=4,m=11,l=16,j=23,U=6,T=10,R=15,O=21;
        s=J(s);C=e(s);Y=1732584193;X=4023233417;W=2562383102;V=271733878;
        for(P=0;P<C.length;P+=16){
            h=Y;E=X;v=W;g=V;Y=u(Y,X,W,V,C[P+0],S,3614090360);V=u(V,Y,X,W,C[P+1],Q,3905402710);W=u(W,V,Y,X,C[P+2],N,606105819);X=u(X,W,V,Y,C[P+3],M,3250441966);Y=u(Y,X,W,V,C[P+4],S,4118548399);V=u(V,Y,X,W,C[P+5],Q,1200080426);W=u(W,V,Y,X,C[P+6],N,2821735955);X=u(X,W,V,Y,C[P+7],M,4249261313);Y=u(Y,X,W,V,C[P+8],S,1770035416);V=u(V,Y,X,W,C[P+9],Q,2336552879);W=u(W,V,Y,X,C[P+10],N,4294925233);X=u(X,W,V,Y,C[P+11],M,2304563134);Y=u(Y,X,W,V,C[P+12],S,1804603682);V=u(V,Y,X,W,C[P+13],Q,4254626195);W=u(W,V,Y,X,C[P+14],N,2792965006);X=u(X,W,V,Y,C[P+15],M,1236535329);Y=f(Y,X,W,V,C[P+1],A,4129170786);V=f(V,Y,X,W,C[P+6],z,3225465664);W=f(W,V,Y,X,C[P+11],y,643717713);X=f(X,W,V,Y,C[P+0],w,3921069994);Y=f(Y,X,W,V,C[P+5],A,3593408605);V=f(V,Y,X,W,C[P+10],z,38016083);W=f(W,V,Y,X,C[P+15],y,3634488961);X=f(X,W,V,Y,C[P+4],w,3889429448);Y=f(Y,X,W,V,C[P+9],A,568446438);V=f(V,Y,X,W,C[P+14],z,3275163606);W=f(W,V,Y,X,C[P+3],y,4107603335);X=f(X,W,V,Y,C[P+8],w,1163531501);Y=f(Y,X,W,V,C[P+13],A,2850285829);V=f(V,Y,X,W,C[P+2],z,4243563512);W=f(W,V,Y,X,C[P+7],y,1735328473);X=f(X,W,V,Y,C[P+12],w,2368359562);Y=D(Y,X,W,V,C[P+5],o,4294588738);V=D(V,Y,X,W,C[P+8],m,2272392833);W=D(W,V,Y,X,C[P+11],l,1839030562);X=D(X,W,V,Y,C[P+14],j,4259657740);Y=D(Y,X,W,V,C[P+1],o,2763975236);V=D(V,Y,X,W,C[P+4],m,1272893353);W=D(W,V,Y,X,C[P+7],l,4139469664);X=D(X,W,V,Y,C[P+10],j,3200236656);Y=D(Y,X,W,V,C[P+13],o,681279174);V=D(V,Y,X,W,C[P+0],m,3936430074);W=D(W,V,Y,X,C[P+3],l,3572445317);X=D(X,W,V,Y,C[P+6],j,76029189);Y=D(Y,X,W,V,C[P+9],o,3654602809);V=D(V,Y,X,W,C[P+12],m,3873151461);W=D(W,V,Y,X,C[P+15],l,530742520);X=D(X,W,V,Y,C[P+2],j,3299628645);Y=t(Y,X,W,V,C[P+0],U,4096336452);V=t(V,Y,X,W,C[P+7],T,1126891415);W=t(W,V,Y,X,C[P+14],R,2878612391);X=t(X,W,V,Y,C[P+5],O,4237533241);Y=t(Y,X,W,V,C[P+12],U,1700485571);V=t(V,Y,X,W,C[P+3],T,2399980690);W=t(W,V,Y,X,C[P+10],R,4293915773);X=t(X,W,V,Y,C[P+1],O,2240044497);Y=t(Y,X,W,V,C[P+8],U,1873313359);V=t(V,Y,X,W,C[P+15],T,4264355552);W=t(W,V,Y,X,C[P+6],R,2734768916);X=t(X,W,V,Y,C[P+13],O,1309151649);Y=t(Y,X,W,V,C[P+4],U,4149444226);V=t(V,Y,X,W,C[P+11],T,3174756917);W=t(W,V,Y,X,C[P+2],R,718787259);X=t(X,W,V,Y,C[P+9],O,3951481745);Y=K(Y,h);X=K(X,E);W=K(W,v);V=K(V,g);
        }
        i=B(Y)+B(X)+B(W)+B(V);
        return i.toLowerCase();
    }
 
    /**
     * URL safe version of blowfish encrypt and decrypt algorithms.
     * enginesis.blowfish.encryptString(data, key)
     * enginesis.blowfish.decryptString(data, key)
     * Encrypted string is the URL-safe escaped version of base-64: translates +/= to -_~
     * Data must be string.
     * Key must be hex digits represented as string "0123456789abcdef"
     * Uses ECB mode only.
     */
    const blowfishWrapper = (function () {
        var crypto={};
        var base64={};
        var p="=";
        var tab="ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

        base64.encode=function(ba){
            var s=[], l=ba.length;
            var rm=l%3;
            var x=l-rm;
            for (var i=0; i<x;){
                var t=ba[i++]<<16|ba[i++]<<8|ba[i++];
                s.push(tab.charAt((t>>>18)&0x3f));
                s.push(tab.charAt((t>>>12)&0x3f));
                s.push(tab.charAt((t>>>6)&0x3f));
                s.push(tab.charAt(t&0x3f));
            }
            switch(rm){
                case 2:{
                    var t=ba[i++]<<16|ba[i++]<<8;
                    s.push(tab.charAt((t>>>18)&0x3f));
                    s.push(tab.charAt((t>>>12)&0x3f));
                    s.push(tab.charAt((t>>>6)&0x3f));
                    s.push(p);
                    break;
                }
                case 1:{
                    var t=ba[i++]<<16;
                    s.push(tab.charAt((t>>>18)&0x3f));
                    s.push(tab.charAt((t>>>12)&0x3f));
                    s.push(p);
                    s.push(p);
                    break;
                }
            }
            return s.join("");
        };

        base64.decode=function(str){
            var s=str.split(""), out=[];
            var l=s.length;
            while(s[--l]==p){ }
            for (var i=0; i<l;){
                var t=tab.indexOf(s[i++])<<18;
                if(i<=l){ t|=tab.indexOf(s[i++])<<12 };
                if(i<=l){ t|=tab.indexOf(s[i++])<<6 };
                if(i<=l){ t|=tab.indexOf(s[i++]) };
                out.push((t>>>16)&0xff);
                out.push((t>>>8)&0xff);
                out.push(t&0xff);
            }
            while(out[out.length-1]==0){ out.pop(); }
            return out;
        };

        function arrayMapWithHoles(arr, callback, thisObject, Ctr){
            var i = 0, l = arr && arr.length || 0, out = new (Ctr || Array)(l);
            if(l && typeof arr == "string") arr = arr.split("");
            if(typeof callback == "string") callback = cache[callback] || buildFn(callback);
            if(thisObject){
                for(; i < l; ++i){
                    out[i] = callback.call(thisObject, arr[i], i, arr);
                }
            }else{
                for(; i < l; ++i){
                    out[i] = callback(arr[i], i, arr);
                }
            }
            return out;
        };

        function stringTranslate(string, undesired, desired) {
            var i, char, found, length, result = "";
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
        }

        crypto.blowfish = new function(){
            var POW8=Math.pow(2,8);
            var POW16=Math.pow(2,16);
            var POW24=Math.pow(2,24);
            var iv=null;
            var boxes={
                p:[
                    0x243f6a88, 0x85a308d3, 0x13198a2e, 0x03707344, 0xa4093822, 0x299f31d0, 0x082efa98, 0xec4e6c89,
                    0x452821e6, 0x38d01377, 0xbe5466cf, 0x34e90c6c, 0xc0ac29b7, 0xc97c50dd, 0x3f84d5b5, 0xb5470917,
                    0x9216d5d9, 0x8979fb1b
                ],
                s0:[
                    0xd1310ba6, 0x98dfb5ac, 0x2ffd72db, 0xd01adfb7, 0xb8e1afed, 0x6a267e96, 0xba7c9045, 0xf12c7f99,
                    0x24a19947, 0xb3916cf7, 0x0801f2e2, 0x858efc16, 0x636920d8, 0x71574e69, 0xa458fea3, 0xf4933d7e,
                    0x0d95748f, 0x728eb658, 0x718bcd58, 0x82154aee, 0x7b54a41d, 0xc25a59b5, 0x9c30d539, 0x2af26013,
                    0xc5d1b023, 0x286085f0, 0xca417918, 0xb8db38ef, 0x8e79dcb0, 0x603a180e, 0x6c9e0e8b, 0xb01e8a3e,
                    0xd71577c1, 0xbd314b27, 0x78af2fda, 0x55605c60, 0xe65525f3, 0xaa55ab94, 0x57489862, 0x63e81440,
                    0x55ca396a, 0x2aab10b6, 0xb4cc5c34, 0x1141e8ce, 0xa15486af, 0x7c72e993, 0xb3ee1411, 0x636fbc2a,
                    0x2ba9c55d, 0x741831f6, 0xce5c3e16, 0x9b87931e, 0xafd6ba33, 0x6c24cf5c, 0x7a325381, 0x28958677,
                    0x3b8f4898, 0x6b4bb9af, 0xc4bfe81b, 0x66282193, 0x61d809cc, 0xfb21a991, 0x487cac60, 0x5dec8032,
                    0xef845d5d, 0xe98575b1, 0xdc262302, 0xeb651b88, 0x23893e81, 0xd396acc5, 0x0f6d6ff3, 0x83f44239,
                    0x2e0b4482, 0xa4842004, 0x69c8f04a, 0x9e1f9b5e, 0x21c66842, 0xf6e96c9a, 0x670c9c61, 0xabd388f0,
                    0x6a51a0d2, 0xd8542f68, 0x960fa728, 0xab5133a3, 0x6eef0b6c, 0x137a3be4, 0xba3bf050, 0x7efb2a98,
                    0xa1f1651d, 0x39af0176, 0x66ca593e, 0x82430e88, 0x8cee8619, 0x456f9fb4, 0x7d84a5c3, 0x3b8b5ebe,
                    0xe06f75d8, 0x85c12073, 0x401a449f, 0x56c16aa6, 0x4ed3aa62, 0x363f7706, 0x1bfedf72, 0x429b023d,
                    0x37d0d724, 0xd00a1248, 0xdb0fead3, 0x49f1c09b, 0x075372c9, 0x80991b7b, 0x25d479d8, 0xf6e8def7,
                    0xe3fe501a, 0xb6794c3b, 0x976ce0bd, 0x04c006ba, 0xc1a94fb6, 0x409f60c4, 0x5e5c9ec2, 0x196a2463,
                    0x68fb6faf, 0x3e6c53b5, 0x1339b2eb, 0x3b52ec6f, 0x6dfc511f, 0x9b30952c, 0xcc814544, 0xaf5ebd09,
                    0xbee3d004, 0xde334afd, 0x660f2807, 0x192e4bb3, 0xc0cba857, 0x45c8740f, 0xd20b5f39, 0xb9d3fbdb,
                    0x5579c0bd, 0x1a60320a, 0xd6a100c6, 0x402c7279, 0x679f25fe, 0xfb1fa3cc, 0x8ea5e9f8, 0xdb3222f8,
                    0x3c7516df, 0xfd616b15, 0x2f501ec8, 0xad0552ab, 0x323db5fa, 0xfd238760, 0x53317b48, 0x3e00df82,
                    0x9e5c57bb, 0xca6f8ca0, 0x1a87562e, 0xdf1769db, 0xd542a8f6, 0x287effc3, 0xac6732c6, 0x8c4f5573,
                    0x695b27b0, 0xbbca58c8, 0xe1ffa35d, 0xb8f011a0, 0x10fa3d98, 0xfd2183b8, 0x4afcb56c, 0x2dd1d35b,
                    0x9a53e479, 0xb6f84565, 0xd28e49bc, 0x4bfb9790, 0xe1ddf2da, 0xa4cb7e33, 0x62fb1341, 0xcee4c6e8,
                    0xef20cada, 0x36774c01, 0xd07e9efe, 0x2bf11fb4, 0x95dbda4d, 0xae909198, 0xeaad8e71, 0x6b93d5a0,
                    0xd08ed1d0, 0xafc725e0, 0x8e3c5b2f, 0x8e7594b7, 0x8ff6e2fb, 0xf2122b64, 0x8888b812, 0x900df01c,
                    0x4fad5ea0, 0x688fc31c, 0xd1cff191, 0xb3a8c1ad, 0x2f2f2218, 0xbe0e1777, 0xea752dfe, 0x8b021fa1,
                    0xe5a0cc0f, 0xb56f74e8, 0x18acf3d6, 0xce89e299, 0xb4a84fe0, 0xfd13e0b7, 0x7cc43b81, 0xd2ada8d9,
                    0x165fa266, 0x80957705, 0x93cc7314, 0x211a1477, 0xe6ad2065, 0x77b5fa86, 0xc75442f5, 0xfb9d35cf,
                    0xebcdaf0c, 0x7b3e89a0, 0xd6411bd3, 0xae1e7e49, 0x00250e2d, 0x2071b35e, 0x226800bb, 0x57b8e0af,
                    0x2464369b, 0xf009b91e, 0x5563911d, 0x59dfa6aa, 0x78c14389, 0xd95a537f, 0x207d5ba2, 0x02e5b9c5,
                    0x83260376, 0x6295cfa9, 0x11c81968, 0x4e734a41, 0xb3472dca, 0x7b14a94a, 0x1b510052, 0x9a532915,
                    0xd60f573f, 0xbc9bc6e4, 0x2b60a476, 0x81e67400, 0x08ba6fb5, 0x571be91f, 0xf296ec6b, 0x2a0dd915,
                    0xb6636521, 0xe7b9f9b6, 0xff34052e, 0xc5855664, 0x53b02d5d, 0xa99f8fa1, 0x08ba4799, 0x6e85076a
                ],
                s1:[
                    0x4b7a70e9, 0xb5b32944, 0xdb75092e, 0xc4192623, 0xad6ea6b0, 0x49a7df7d, 0x9cee60b8, 0x8fedb266,
                    0xecaa8c71, 0x699a17ff, 0x5664526c, 0xc2b19ee1, 0x193602a5, 0x75094c29, 0xa0591340, 0xe4183a3e,
                    0x3f54989a, 0x5b429d65, 0x6b8fe4d6, 0x99f73fd6, 0xa1d29c07, 0xefe830f5, 0x4d2d38e6, 0xf0255dc1,
                    0x4cdd2086, 0x8470eb26, 0x6382e9c6, 0x021ecc5e, 0x09686b3f, 0x3ebaefc9, 0x3c971814, 0x6b6a70a1,
                    0x687f3584, 0x52a0e286, 0xb79c5305, 0xaa500737, 0x3e07841c, 0x7fdeae5c, 0x8e7d44ec, 0x5716f2b8,
                    0xb03ada37, 0xf0500c0d, 0xf01c1f04, 0x0200b3ff, 0xae0cf51a, 0x3cb574b2, 0x25837a58, 0xdc0921bd,
                    0xd19113f9, 0x7ca92ff6, 0x94324773, 0x22f54701, 0x3ae5e581, 0x37c2dadc, 0xc8b57634, 0x9af3dda7,
                    0xa9446146, 0x0fd0030e, 0xecc8c73e, 0xa4751e41, 0xe238cd99, 0x3bea0e2f, 0x3280bba1, 0x183eb331,
                    0x4e548b38, 0x4f6db908, 0x6f420d03, 0xf60a04bf, 0x2cb81290, 0x24977c79, 0x5679b072, 0xbcaf89af,
                    0xde9a771f, 0xd9930810, 0xb38bae12, 0xdccf3f2e, 0x5512721f, 0x2e6b7124, 0x501adde6, 0x9f84cd87,
                    0x7a584718, 0x7408da17, 0xbc9f9abc, 0xe94b7d8c, 0xec7aec3a, 0xdb851dfa, 0x63094366, 0xc464c3d2,
                    0xef1c1847, 0x3215d908, 0xdd433b37, 0x24c2ba16, 0x12a14d43, 0x2a65c451, 0x50940002, 0x133ae4dd,
                    0x71dff89e, 0x10314e55, 0x81ac77d6, 0x5f11199b, 0x043556f1, 0xd7a3c76b, 0x3c11183b, 0x5924a509,
                    0xf28fe6ed, 0x97f1fbfa, 0x9ebabf2c, 0x1e153c6e, 0x86e34570, 0xeae96fb1, 0x860e5e0a, 0x5a3e2ab3,
                    0x771fe71c, 0x4e3d06fa, 0x2965dcb9, 0x99e71d0f, 0x803e89d6, 0x5266c825, 0x2e4cc978, 0x9c10b36a,
                    0xc6150eba, 0x94e2ea78, 0xa5fc3c53, 0x1e0a2df4, 0xf2f74ea7, 0x361d2b3d, 0x1939260f, 0x19c27960,
                    0x5223a708, 0xf71312b6, 0xebadfe6e, 0xeac31f66, 0xe3bc4595, 0xa67bc883, 0xb17f37d1, 0x018cff28,
                    0xc332ddef, 0xbe6c5aa5, 0x65582185, 0x68ab9802, 0xeecea50f, 0xdb2f953b, 0x2aef7dad, 0x5b6e2f84,
                    0x1521b628, 0x29076170, 0xecdd4775, 0x619f1510, 0x13cca830, 0xeb61bd96, 0x0334fe1e, 0xaa0363cf,
                    0xb5735c90, 0x4c70a239, 0xd59e9e0b, 0xcbaade14, 0xeecc86bc, 0x60622ca7, 0x9cab5cab, 0xb2f3846e,
                    0x648b1eaf, 0x19bdf0ca, 0xa02369b9, 0x655abb50, 0x40685a32, 0x3c2ab4b3, 0x319ee9d5, 0xc021b8f7,
                    0x9b540b19, 0x875fa099, 0x95f7997e, 0x623d7da8, 0xf837889a, 0x97e32d77, 0x11ed935f, 0x16681281,
                    0x0e358829, 0xc7e61fd6, 0x96dedfa1, 0x7858ba99, 0x57f584a5, 0x1b227263, 0x9b83c3ff, 0x1ac24696,
                    0xcdb30aeb, 0x532e3054, 0x8fd948e4, 0x6dbc3128, 0x58ebf2ef, 0x34c6ffea, 0xfe28ed61, 0xee7c3c73,
                    0x5d4a14d9, 0xe864b7e3, 0x42105d14, 0x203e13e0, 0x45eee2b6, 0xa3aaabea, 0xdb6c4f15, 0xfacb4fd0,
                    0xc742f442, 0xef6abbb5, 0x654f3b1d, 0x41cd2105, 0xd81e799e, 0x86854dc7, 0xe44b476a, 0x3d816250,
                    0xcf62a1f2, 0x5b8d2646, 0xfc8883a0, 0xc1c7b6a3, 0x7f1524c3, 0x69cb7492, 0x47848a0b, 0x5692b285,
                    0x095bbf00, 0xad19489d, 0x1462b174, 0x23820e00, 0x58428d2a, 0x0c55f5ea, 0x1dadf43e, 0x233f7061,
                    0x3372f092, 0x8d937e41, 0xd65fecf1, 0x6c223bdb, 0x7cde3759, 0xcbee7460, 0x4085f2a7, 0xce77326e,
                    0xa6078084, 0x19f8509e, 0xe8efd855, 0x61d99735, 0xa969a7aa, 0xc50c06c2, 0x5a04abfc, 0x800bcadc,
                    0x9e447a2e, 0xc3453484, 0xfdd56705, 0x0e1e9ec9, 0xdb73dbd3, 0x105588cd, 0x675fda79, 0xe3674340,
                    0xc5c43465, 0x713e38d8, 0x3d28f89e, 0xf16dff20, 0x153e21e7, 0x8fb03d4a, 0xe6e39f2b, 0xdb83adf7
                ],
                s2:[
                    0xe93d5a68, 0x948140f7, 0xf64c261c, 0x94692934, 0x411520f7, 0x7602d4f7, 0xbcf46b2e, 0xd4a20068,
                    0xd4082471, 0x3320f46a, 0x43b7d4b7, 0x500061af, 0x1e39f62e, 0x97244546, 0x14214f74, 0xbf8b8840,
                    0x4d95fc1d, 0x96b591af, 0x70f4ddd3, 0x66a02f45, 0xbfbc09ec, 0x03bd9785, 0x7fac6dd0, 0x31cb8504,
                    0x96eb27b3, 0x55fd3941, 0xda2547e6, 0xabca0a9a, 0x28507825, 0x530429f4, 0x0a2c86da, 0xe9b66dfb,
                    0x68dc1462, 0xd7486900, 0x680ec0a4, 0x27a18dee, 0x4f3ffea2, 0xe887ad8c, 0xb58ce006, 0x7af4d6b6,
                    0xaace1e7c, 0xd3375fec, 0xce78a399, 0x406b2a42, 0x20fe9e35, 0xd9f385b9, 0xee39d7ab, 0x3b124e8b,
                    0x1dc9faf7, 0x4b6d1856, 0x26a36631, 0xeae397b2, 0x3a6efa74, 0xdd5b4332, 0x6841e7f7, 0xca7820fb,
                    0xfb0af54e, 0xd8feb397, 0x454056ac, 0xba489527, 0x55533a3a, 0x20838d87, 0xfe6ba9b7, 0xd096954b,
                    0x55a867bc, 0xa1159a58, 0xcca92963, 0x99e1db33, 0xa62a4a56, 0x3f3125f9, 0x5ef47e1c, 0x9029317c,
                    0xfdf8e802, 0x04272f70, 0x80bb155c, 0x05282ce3, 0x95c11548, 0xe4c66d22, 0x48c1133f, 0xc70f86dc,
                    0x07f9c9ee, 0x41041f0f, 0x404779a4, 0x5d886e17, 0x325f51eb, 0xd59bc0d1, 0xf2bcc18f, 0x41113564,
                    0x257b7834, 0x602a9c60, 0xdff8e8a3, 0x1f636c1b, 0x0e12b4c2, 0x02e1329e, 0xaf664fd1, 0xcad18115,
                    0x6b2395e0, 0x333e92e1, 0x3b240b62, 0xeebeb922, 0x85b2a20e, 0xe6ba0d99, 0xde720c8c, 0x2da2f728,
                    0xd0127845, 0x95b794fd, 0x647d0862, 0xe7ccf5f0, 0x5449a36f, 0x877d48fa, 0xc39dfd27, 0xf33e8d1e,
                    0x0a476341, 0x992eff74, 0x3a6f6eab, 0xf4f8fd37, 0xa812dc60, 0xa1ebddf8, 0x991be14c, 0xdb6e6b0d,
                    0xc67b5510, 0x6d672c37, 0x2765d43b, 0xdcd0e804, 0xf1290dc7, 0xcc00ffa3, 0xb5390f92, 0x690fed0b,
                    0x667b9ffb, 0xcedb7d9c, 0xa091cf0b, 0xd9155ea3, 0xbb132f88, 0x515bad24, 0x7b9479bf, 0x763bd6eb,
                    0x37392eb3, 0xcc115979, 0x8026e297, 0xf42e312d, 0x6842ada7, 0xc66a2b3b, 0x12754ccc, 0x782ef11c,
                    0x6a124237, 0xb79251e7, 0x06a1bbe6, 0x4bfb6350, 0x1a6b1018, 0x11caedfa, 0x3d25bdd8, 0xe2e1c3c9,
                    0x44421659, 0x0a121386, 0xd90cec6e, 0xd5abea2a, 0x64af674e, 0xda86a85f, 0xbebfe988, 0x64e4c3fe,
                    0x9dbc8057, 0xf0f7c086, 0x60787bf8, 0x6003604d, 0xd1fd8346, 0xf6381fb0, 0x7745ae04, 0xd736fccc,
                    0x83426b33, 0xf01eab71, 0xb0804187, 0x3c005e5f, 0x77a057be, 0xbde8ae24, 0x55464299, 0xbf582e61,
                    0x4e58f48f, 0xf2ddfda2, 0xf474ef38, 0x8789bdc2, 0x5366f9c3, 0xc8b38e74, 0xb475f255, 0x46fcd9b9,
                    0x7aeb2661, 0x8b1ddf84, 0x846a0e79, 0x915f95e2, 0x466e598e, 0x20b45770, 0x8cd55591, 0xc902de4c,
                    0xb90bace1, 0xbb8205d0, 0x11a86248, 0x7574a99e, 0xb77f19b6, 0xe0a9dc09, 0x662d09a1, 0xc4324633,
                    0xe85a1f02, 0x09f0be8c, 0x4a99a025, 0x1d6efe10, 0x1ab93d1d, 0x0ba5a4df, 0xa186f20f, 0x2868f169,
                    0xdcb7da83, 0x573906fe, 0xa1e2ce9b, 0x4fcd7f52, 0x50115e01, 0xa70683fa, 0xa002b5c4, 0x0de6d027,
                    0x9af88c27, 0x773f8641, 0xc3604c06, 0x61a806b5, 0xf0177a28, 0xc0f586e0, 0x006058aa, 0x30dc7d62,
                    0x11e69ed7, 0x2338ea63, 0x53c2dd94, 0xc2c21634, 0xbbcbee56, 0x90bcb6de, 0xebfc7da1, 0xce591d76,
                    0x6f05e409, 0x4b7c0188, 0x39720a3d, 0x7c927c24, 0x86e3725f, 0x724d9db9, 0x1ac15bb4, 0xd39eb8fc,
                    0xed545578, 0x08fca5b5, 0xd83d7cd3, 0x4dad0fc4, 0x1e50ef5e, 0xb161e6f8, 0xa28514d9, 0x6c51133c,
                    0x6fd5c7e7, 0x56e14ec4, 0x362abfce, 0xddc6c837, 0xd79a3234, 0x92638212, 0x670efa8e, 0x406000e0
                ],
                s3:[
                    0x3a39ce37, 0xd3faf5cf, 0xabc27737, 0x5ac52d1b, 0x5cb0679e, 0x4fa33742, 0xd3822740, 0x99bc9bbe,
                    0xd5118e9d, 0xbf0f7315, 0xd62d1c7e, 0xc700c47b, 0xb78c1b6b, 0x21a19045, 0xb26eb1be, 0x6a366eb4,
                    0x5748ab2f, 0xbc946e79, 0xc6a376d2, 0x6549c2c8, 0x530ff8ee, 0x468dde7d, 0xd5730a1d, 0x4cd04dc6,
                    0x2939bbdb, 0xa9ba4650, 0xac9526e8, 0xbe5ee304, 0xa1fad5f0, 0x6a2d519a, 0x63ef8ce2, 0x9a86ee22,
                    0xc089c2b8, 0x43242ef6, 0xa51e03aa, 0x9cf2d0a4, 0x83c061ba, 0x9be96a4d, 0x8fe51550, 0xba645bd6,
                    0x2826a2f9, 0xa73a3ae1, 0x4ba99586, 0xef5562e9, 0xc72fefd3, 0xf752f7da, 0x3f046f69, 0x77fa0a59,
                    0x80e4a915, 0x87b08601, 0x9b09e6ad, 0x3b3ee593, 0xe990fd5a, 0x9e34d797, 0x2cf0b7d9, 0x022b8b51,
                    0x96d5ac3a, 0x017da67d, 0xd1cf3ed6, 0x7c7d2d28, 0x1f9f25cf, 0xadf2b89b, 0x5ad6b472, 0x5a88f54c,
                    0xe029ac71, 0xe019a5e6, 0x47b0acfd, 0xed93fa9b, 0xe8d3c48d, 0x283b57cc, 0xf8d56629, 0x79132e28,
                    0x785f0191, 0xed756055, 0xf7960e44, 0xe3d35e8c, 0x15056dd4, 0x88f46dba, 0x03a16125, 0x0564f0bd,
                    0xc3eb9e15, 0x3c9057a2, 0x97271aec, 0xa93a072a, 0x1b3f6d9b, 0x1e6321f5, 0xf59c66fb, 0x26dcf319,
                    0x7533d928, 0xb155fdf5, 0x03563482, 0x8aba3cbb, 0x28517711, 0xc20ad9f8, 0xabcc5167, 0xccad925f,
                    0x4de81751, 0x3830dc8e, 0x379d5862, 0x9320f991, 0xea7a90c2, 0xfb3e7bce, 0x5121ce64, 0x774fbe32,
                    0xa8b6e37e, 0xc3293d46, 0x48de5369, 0x6413e680, 0xa2ae0810, 0xdd6db224, 0x69852dfd, 0x09072166,
                    0xb39a460a, 0x6445c0dd, 0x586cdecf, 0x1c20c8ae, 0x5bbef7dd, 0x1b588d40, 0xccd2017f, 0x6bb4e3bb,
                    0xdda26a7e, 0x3a59ff45, 0x3e350a44, 0xbcb4cdd5, 0x72eacea8, 0xfa6484bb, 0x8d6612ae, 0xbf3c6f47,
                    0xd29be463, 0x542f5d9e, 0xaec2771b, 0xf64e6370, 0x740e0d8d, 0xe75b1357, 0xf8721671, 0xaf537d5d,
                    0x4040cb08, 0x4eb4e2cc, 0x34d2466a, 0x0115af84, 0xe1b00428, 0x95983a1d, 0x06b89fb4, 0xce6ea048,
                    0x6f3f3b82, 0x3520ab82, 0x011a1d4b, 0x277227f8, 0x611560b1, 0xe7933fdc, 0xbb3a792b, 0x344525bd,
                    0xa08839e1, 0x51ce794b, 0x2f32c9b7, 0xa01fbac9, 0xe01cc87e, 0xbcc7d1f6, 0xcf0111c3, 0xa1e8aac7,
                    0x1a908749, 0xd44fbd9a, 0xd0dadecb, 0xd50ada38, 0x0339c32a, 0xc6913667, 0x8df9317c, 0xe0b12b4f,
                    0xf79e59b7, 0x43f5bb3a, 0xf2d519ff, 0x27d9459c, 0xbf97222c, 0x15e6fc2a, 0x0f91fc71, 0x9b941525,
                    0xfae59361, 0xceb69ceb, 0xc2a86459, 0x12baa8d1, 0xb6c1075e, 0xe3056a0c, 0x10d25065, 0xcb03a442,
                    0xe0ec6e0e, 0x1698db3b, 0x4c98a0be, 0x3278e964, 0x9f1f9532, 0xe0d392df, 0xd3a0342b, 0x8971f21e,
                    0x1b0a7441, 0x4ba3348c, 0xc5be7120, 0xc37632d8, 0xdf359f8d, 0x9b992f2e, 0xe60b6f47, 0x0fe3f11d,
                    0xe54cda54, 0x1edad891, 0xce6279cf, 0xcd3e7e6f, 0x1618b166, 0xfd2c1d05, 0x848fd2c5, 0xf6fb2299,
                    0xf523f357, 0xa6327623, 0x93a83531, 0x56cccd02, 0xacf08162, 0x5a75ebb5, 0x6e163697, 0x88d273cc,
                    0xde966292, 0x81b949d0, 0x4c50901b, 0x71c65614, 0xe6c6c7bd, 0x327a140a, 0x45e1d006, 0xc3f27b9a,
                    0xc9aa53fd, 0x62a80f00, 0xbb25bfe2, 0x35bdd2f6, 0x71126905, 0xb2040222, 0xb6cbcf7c, 0xcd769c2b,
                    0x53113ec0, 0x1640e3d3, 0x38abbd60, 0x2547adf0, 0xba38209c, 0xf746ce76, 0x77afa1c5, 0x20756060,
                    0x85cbfe4e, 0x8ae88dd8, 0x7aaaf9b0, 0x4cf9aa7e, 0x1948c25c, 0x02fb8a8c, 0x01c36ae4, 0xd6ebe1f9,
                    0x90d4f869, 0xa65cdea0, 0x3f09252d, 0xc208e69f, 0xb74e6132, 0xce77e25b, 0x578fdfe3, 0x3ac372e6
                ]
            }

            function add(x,y){
                return (((x>>0x10)+(y>>0x10)+(((x&0xffff)+(y&0xffff))>>0x10))<<0x10)|(((x&0xffff)+(y&0xffff))&0xffff);
            }

            function xor(x,y){
                return (((x>>0x10)^(y>>0x10))<<0x10)|(((x&0xffff)^(y&0xffff))&0xffff);
            }

            function $(v, box){
                var d=box.s3[v&0xff]; v>>=8;
                var c=box.s2[v&0xff]; v>>=8;
                var b=box.s1[v&0xff]; v>>=8;
                var a=box.s0[v&0xff];

                var r = (((a>>0x10)+(b>>0x10)+(((a&0xffff)+(b&0xffff))>>0x10))<<0x10)|(((a&0xffff)+(b&0xffff))&0xffff);
                r = (((r>>0x10)^(c>>0x10))<<0x10)|(((r&0xffff)^(c&0xffff))&0xffff);
                return (((r>>0x10)+(d>>0x10)+(((r&0xffff)+(d&0xffff))>>0x10))<<0x10)|(((r&0xffff)+(d&0xffff))&0xffff);
            }

            function eb(o, box){
                var l=o.left;
                var r=o.right;
                l=xor(l,box.p[0]);
                r=xor(r,xor($(l,box),box.p[1]));
                l=xor(l,xor($(r,box),box.p[2]));
                r=xor(r,xor($(l,box),box.p[3]));
                l=xor(l,xor($(r,box),box.p[4]));
                r=xor(r,xor($(l,box),box.p[5]));
                l=xor(l,xor($(r,box),box.p[6]));
                r=xor(r,xor($(l,box),box.p[7]));
                l=xor(l,xor($(r,box),box.p[8]));
                r=xor(r,xor($(l,box),box.p[9]));
                l=xor(l,xor($(r,box),box.p[10]));
                r=xor(r,xor($(l,box),box.p[11]));
                l=xor(l,xor($(r,box),box.p[12]));
                r=xor(r,xor($(l,box),box.p[13]));
                l=xor(l,xor($(r,box),box.p[14]));
                r=xor(r,xor($(l,box),box.p[15]));
                l=xor(l,xor($(r,box),box.p[16]));
                o.right=l;
                o.left=xor(r,box.p[17]);
            }

            function db(o, box){
                var l=o.left;
                var r=o.right;
                l=xor(l,box.p[17]);
                r=xor(r,xor($(l,box),box.p[16]));
                l=xor(l,xor($(r,box),box.p[15]));
                r=xor(r,xor($(l,box),box.p[14]));
                l=xor(l,xor($(r,box),box.p[13]));
                r=xor(r,xor($(l,box),box.p[12]));
                l=xor(l,xor($(r,box),box.p[11]));
                r=xor(r,xor($(l,box),box.p[10]));
                l=xor(l,xor($(r,box),box.p[9]));
                r=xor(r,xor($(l,box),box.p[8]));
                l=xor(l,xor($(r,box),box.p[7]));
                r=xor(r,xor($(l,box),box.p[6]));
                l=xor(l,xor($(r,box),box.p[5]));
                r=xor(r,xor($(l,box),box.p[4]));
                l=xor(l,xor($(r,box),box.p[3]));
                r=xor(r,xor($(l,box),box.p[2]));
                l=xor(l,xor($(r,box),box.p[1]));
                o.right=l;
                o.left=xor(r,box.p[0]);
            }

            function init(key){
                var k=key, pos=0, data=0, res={ left:0, right:0 }, i, j, l;
                var box = {
                    p: arrayMapWithHoles(boxes.p.slice(0), function(item){
                        var l=k.length, j;
                        for(j=0; j<4; j++){ data=(data*POW8)|k[pos++ % l]; }
                        return (((item>>0x10)^(data>>0x10))<<0x10)|(((item&0xffff)^(data&0xffff))&0xffff);
                    }),
                    s0:boxes.s0.slice(0),
                    s1:boxes.s1.slice(0),
                    s2:boxes.s2.slice(0),
                    s3:boxes.s3.slice(0)
                };
                for(i=0, l=box.p.length; i<l;){
                    eb(res, box);
                    box.p[i++]=res.left, box.p[i++]=res.right;
                }
                for(i=0; i<4; i++){
                    for(j=0, l=box["s"+i].length; j<l;){
                        eb(res, box);
                        box["s"+i][j++]=res.left, box["s"+i][j++]=res.right;
                    }
                }
                return box;
            }

            this.hexStringToByteArray=function(hexString) {
                if (hexString.length % 2 == 1) {
                    hexString += "0";
                }
                for (var bytes = [], index = 0; index < hexString.length; index += 2) {
                    bytes.push(parseInt(hexString.substring(index, index+2), 16));
                }
                return bytes;
            }

            this.getIV=function(){
                return base64.encode(iv);
            };

            this.setIV=function(data){
                var ba=base64.decode(data);
                iv={};
                iv.left=ba[0]*POW24|ba[1]*POW16|ba[2]*POW8|ba[3];
                iv.right=ba[4]*POW24|ba[5]*POW16|ba[6]*POW8|ba[7];
            };

            this.encryptString = function(plaintext, key){
                var bx = init(this.hexStringToByteArray(key)), padding = 8-(plaintext.length&7);
                for (var i=0; i<padding; i++){ plaintext+=String.fromCharCode(padding); }
                var cipher=[], count=plaintext.length >> 3, pos=0, o={};
                for(var i=0; i<count; i++){
                    o.left=plaintext.charCodeAt(pos)*POW24
                        |plaintext.charCodeAt(pos+1)*POW16
                        |plaintext.charCodeAt(pos+2)*POW8
                        |plaintext.charCodeAt(pos+3);
                    o.right=plaintext.charCodeAt(pos+4)*POW24
                        |plaintext.charCodeAt(pos+5)*POW16
                        |plaintext.charCodeAt(pos+6)*POW8
                        |plaintext.charCodeAt(pos+7);
                    eb(o, bx);
                    cipher.push((o.left>>24)&0xff);
                    cipher.push((o.left>>16)&0xff);
                    cipher.push((o.left>>8)&0xff);
                    cipher.push(o.left&0xff);
                    cipher.push((o.right>>24)&0xff);
                    cipher.push((o.right>>16)&0xff);
                    cipher.push((o.right>>8)&0xff);
                    cipher.push(o.right&0xff);
                    pos+=8;
                }
                return stringTranslate(base64.encode(cipher), ["+", "/", "="], ["-", "_", "~"]);
            };

            this.decryptString = function(ciphertext, key){
                var bx = init(this.hexStringToByteArray(key));
                var pt=[];
                var c=base64.decode(stringTranslate(ciphertext, ["-", "_", "~"], ["+", "/", "="]));
                var count=c.length >> 3, pos=0, o={};
                for(var i=0; i<count; i++){
                    o.left=c[pos]*POW24|c[pos+1]*POW16|c[pos+2]*POW8|c[pos+3];
                    o.right=c[pos+4]*POW24|c[pos+5]*POW16|c[pos+6]*POW8|c[pos+7];
                    db(o, bx);
                    pt.push((o.left>>24)&0xff);
                    pt.push((o.left>>16)&0xff);
                    pt.push((o.left>>8)&0xff);
                    pt.push(o.left&0xff);
                    pt.push((o.right>>24)&0xff);
                    pt.push((o.right>>16)&0xff);
                    pt.push((o.right>>8)&0xff);
                    pt.push(o.right&0xff);
                    pos+=8;
                }
                if(pt[pt.length-1]==pt[pt.length-2]||pt[pt.length-1]==0x01){
                    var n=pt[pt.length-1];
                    pt.splice(pt.length-n, n);
                }
                return arrayMapWithHoles(pt, function(item){
                    return String.fromCharCode(item);
                }).join("");
            };
            this.setIV("0000000000000000");
        }();
        return crypto.blowfish;
    })();

    /**
     * Verify the hash provided in the response is valid with the data provided. This is an
     * attempt to verify the payload was not tampered with to spoof the session. In order for
     * this to work we expect `enginesis.loggedInUserInfo` was restored from a prior session,
     * either a current session UserLogin, a SessionRefresh, or localStorage/Cookie.
     *
     * @param {object} sessionInfo A session object sent by the Enginesis server.
     * @return {boolean} True if we think the session from the server matches the data we have locally.
     */
    function sessionVerifyGameHash(sessionInfo) {
        var isValid = false;
        var cr = sessionInfo.cr || "";
        var hash = "";
        var userInfo = enginesis.loggedInUserInfo;
        if (cr) {
            hash = sessionMakeGameHash({
                siteId: enginesis.siteId,
                gameId: enginesis.gameId,
                userId: coerceNotEmpty(userInfo.user_id, 0),
                userName: coerceNotEmpty(userInfo.user_name, ""),
                siteUserId: coerceNotEmpty(userInfo.site_user_id, ""),
                accessLevel: coerceNotEmpty(userInfo.access_level, 0),
                siteKey: enginesis.developerKey
            });
            isValid = cr == hash;
            if ( ! isValid) {
                // if not valid, it could be because the users authentication expired
                userInfo.dayStamp = sessionDayStamp();
                hash = sessionMakeGameHash({
                    siteId: enginesis.siteId,
                    gameId: enginesis.gameId,
                    userId: 0,
                    userName: "",
                    siteUserId: "",
                    accessLevel: 10,
                    siteKey: enginesis.developerKey
                });
                isValid = cr == hash;
                if (isValid) {
                    // game session is good but the user must refresh their authentication
                    debugLog("sessionVerifyGameHash Session expired but we think we can refresh it.");
                    enginesis.sessionRefresh(_getRefreshToken(), null)
                    .then(function(enginesisResult) {
                        debugLog("sessionVerifyGameHash users authentication has been refreshed. " + enginesisResult.toString());
                    }, function(enginesisError) {
                        debugLog("sessionVerifyGameHash refresh error " + enginesisError.toString());
                    })
                    .catch(function(exception) {
                        debugLog("sessionVerifyGameHash refresh exception " + exception.toString());
                    });
                }
            }
        }
        if ( ! isValid) {
            debugLog("sessionVerifyGameHash hash does not match. From server: " + cr + ". Computed here: " + hash);
        }
        return true;
    }

    /**
     * This is the callback from a request to refresh the Enginesis login when the auth token
     * expires. This response is similar to the initial login response. Called from `sessionRefresh`.
     * @param {object} enginesisResult Enginesis result object.
     * @returns {boolean} True if successful.
     */
    function refreshSessionInfo(enginesisResult) {
        var refreshSuccessful = false;
        if (enginesisResult && enginesisResult.results && enginesisResult.results.result) {
            var sessionInfo = enginesisResult.results.result[0];

            debugLog(">>>>> enginesis.refreshSessionInfo new session id " + sessionInfo.session_id + " new CR " + sessionInfo.cr);

            // verify session hash so that we know the payload was not tampered with
            if ( ! sessionVerifyHash(sessionInfo.cr, sessionInfo)) {
                debugLog("refreshSessionInfo hash does not match. From server: " + sessionInfo.cr + ". Computed here: " + sessionMakeHash());
                refreshSuccessful = false;
            }
            refreshSuccessful = saveUserSessionInfo(sessionInfo, false);
        } else {
            var errorCode = resultErrorCode(enginesisResult);
            if (errorCode == "INVALID_PARAMETER" || errorCode == "INVALID_TOKEN") {
                // if the refresh token is invalid then log this user out or else
                // we will keep trying this bad token on every request.
                clearUserSessionInfo();
            }
        }
        return refreshSuccessful;
    }

    /**
     * Update the local cache of game information when the server replies with game attributes.
     * @param {object} enginesisResult Enginesis server response object
     */
    function updateGameInfo(enginesisResult) {
        if (enginesisResult.results.result.row) {
            enginesis.gameInfo = enginesisResult.results.result.row;
        } else {
            enginesis.gameInfo = enginesisResult.results.result[0];
        }
        if (coerceBoolean(enginesis.gameInfo.is_favorite)) {
            enginesis.favoriteGames.add(parseInt(enginesis.gameInfo.game_id, 10));
        }
    }

    /**
     * When a list of the user's favorite games is requested intercept the response and
     * update the local cache of favorite games.
     * @param {object} enginesisResult Enginesis server response object
     */
    function updateFavoriteGames(enginesisResult) {
        var serverFavoriteGamesList = enginesisResult.results.result;
        enginesis.favoriteGames.clear();
        for (var i = 0; i < serverFavoriteGamesList.length; i ++) {
            enginesis.favoriteGames.add(parseInt(serverFavoriteGamesList[i].game_id, 10));
        }
    }

    /**
     * Capture the session begin session id so we can use it for communicating with the server.
     * We end up here after a call to `sessionBegin` and this is the server response.
     * @param {object} enginesisResult Enginesis server response object
     */
    function updateGameSessionInfo(enginesisResult) {
        // @todo: check if token expired then call sessionrefresh
        var sessionInfo = enginesisResult.results.result[0];
        if (sessionVerifyGameHash(sessionInfo)) {
            updateGameInfo(enginesisResult);
            if (sessionInfo.authToken || sessionInfo.authtok) {
                saveUserSessionInfo(sessionInfo, true);
            } else {
                enginesis.sessionId = sessionInfo.session_id;
                enginesis.sessionExpires = Date.now() + (24 * 60 * 60 * 1000); // have it expire in 1 day.
                if (sessionInfo.site_mark && sessionInfo.site_mark != enginesis.anonymousUser.userId) {
                    enginesis.anonymousUser.userId = sessionInfo.site_mark;
                    anonymousUserSave();
                }
            }
            if (coerceBoolean(sessionInfo.tokenExpired) && ! isEmpty(enginesis.refreshToken)) {
                // When the server says the token is expired and we have a refresh token, we can request a fresh auth token.
                enginesis.sessionRefresh(enginesis.refreshToken, null);
            }
        }
        enginesis.siteResources.baseURL = sessionInfo.siteBaseUrl || "";
        enginesis.siteResources.profileURL = sessionInfo.profileUrl || "";
        enginesis.siteResources.loginURL = sessionInfo.loginUrl || "";
        enginesis.siteResources.registerURL = sessionInfo.registerUrl || "";
        enginesis.siteResources.forgotPasswordURL = sessionInfo.forgotPasswordUrl || "";
        enginesis.siteResources.playURL = sessionInfo.playUrl || "";
        enginesis.siteResources.privacyURL = sessionInfo.privacyUrl || "";
        enginesis.siteResources.termsURL = sessionInfo.termsUrl || "";
    }

    /**
     * Initialize all user session related data to a known initial state.
     */
    function initializeLocalSessionInfo() {
        enginesis.loggedInUserInfo = {};

        // Clear the session and user info
        enginesis.networkId = 1;
        enginesis.sessionId = null;
        enginesis.sessionExpires = null;
        enginesis.authToken = null;
        enginesis.authTokenWasValidated = false;
        enginesis.authTokenExpires = null;
        enginesis.refreshToken = null;
        enginesis.refreshTokenExpires = null;
    }

    /**
     * After a successful login copy everything we got back from the server about the
     * validated user. For example, we are going to need the session-id, authentication token,
     * and user-id for subsequent transactions with the server.
     * @param {object} enginesisResult Log in data sent from the server.
     * @returns {boolean} True if save is successful, false if error.
     */
    function updateLoggedInUserInfo(enginesisResult) {
        var updated = false;
        if (enginesisResult && enginesisResult.results && enginesisResult.results.result) {
            var userInfo = enginesisResult.results.result[0];

            // verify session hash so that we know the payload was not tampered with
            if ( ! sessionVerifyHash(userInfo.cr, userInfo)) {
                debugLog("updateLoggedInUserInfo hash does not match. From server: " + userInfo.cr + ". Computed here: " + sessionMakeHash(userInfo));
            }
            // after a log in save the refresh token separately from the session.
            _saveRefreshToken(userInfo.refresh_token);

            // Move server authorized user data into the local cache
            enginesis.loggedInUserInfo = userInfo;
            enginesis.networkId = userInfo.network_id;
            updated = saveUserSessionInfo(userInfo, false);
        }
        return updated;
    }

    /**
     * After a successful logout clear everything we know about the user.
     * @param {object} enginesisResult
     */
    function clearLoggedInUserInfo(enginesisResult) {
        if (enginesisResult && enginesisResult.results && enginesisResult.results.result) {
            initializeLocalSessionInfo();
            clearUserSessionInfo();
        }
    }

    /**
     * Compute the Enginesis day stamp for the current day. This must match what the server would compute
     * on the same day in UTC.
     * @returns {Number} The session day stamp value.
     */
    function sessionDayStamp() {
        var SESSION_DAYSTAMP_HOURS = 48;
        return Math.floor(Date.now() / (SESSION_DAYSTAMP_HOURS * 60 * 60 * 1000));
    }

    /**
     * Compute the session hash for the provided session information. If something is missing we will get
     * a default value from the current session, regardless if it is valid or not. It's not really valid
     * calling this function this way if authTokenWasValidated == false. This function matches server-side
     * sessionMakeHash().
     *
     * @param {object} userInfo an object containing the key/value pairs identifying a user session, all of which are optional:
     *    siteId, siteKey, dayStamp, userId, userName, siteUserId, accessLevel
     * @returns {string} The hash for the current user session.
     */
    function sessionMakeHash(userInfo) {
        userInfo = userInfo || {};
        var loggedInUserInfo = enginesis.loggedInUserInfo || {};
        var siteId = enginesis.siteId;
        var userId = coerceNotEmpty(userInfo.userId, userInfo.user_id, loggedInUserInfo.user_id, 0);
        var userName = coerceNotEmpty(userInfo.userName, userInfo.user_name, loggedInUserInfo.user_name, "");
        var accessLevel = coerceNotEmpty(userInfo.accessLevel, userInfo.access_level, loggedInUserInfo.access_level, 10);
        var siteUserId = coerceNotEmpty(userInfo.siteUserId, userInfo.site_user_id, loggedInUserInfo.site_user_id, "");
        var dayStamp = userInfo.dayStamp || sessionDayStamp();
        var hashClear = "s=" + siteId + "&u=" + userId + "&d=" + dayStamp + "&n=" + userName + "&i=" + siteUserId + "&l=" + accessLevel + "&k=" + enginesis.developerKey;
        var hash = md5(hashClear);
        debugLog("sessionMakeHash from " + hashClear + " yields " + hash);
        return hash;
    }

    /**
     * Compute the game session hash for the provided session information. If something is missing we will get
     * a default value from the current session, regardless if it is valid or not. It's not really valid
     * calling this function this way if authTokenWasValidated == false. This function matches server-side
     * sessionMakeGameHash().
     *
     * @param {object} userInfo an object containing the key/value pairs identifying a user session, all of which are optional:
     *    siteId, siteKey, dayStamp, userId, userName, siteUserId, accessLevel
     * @returns {string} The hash for the current game session.
     */
    function sessionMakeGameHash(userInfo) {
        userInfo = userInfo || {};
        var loggedInUserInfo = enginesis.loggedInUserInfo || {};
        var siteId = enginesis.siteId;
        var userId = coerceNotNull(userInfo.userId, userInfo.user_id, loggedInUserInfo.user_id, 0);
        var userName = coerceNotNull(userInfo.userName, userInfo.user_name, loggedInUserInfo.user_name, "");
        var accessLevel = coerceNotNull(userInfo.accessLevel, userInfo.access_level, loggedInUserInfo.access_level, 10);
        var siteUserId = coerceNotNull(userInfo.siteUserId, userInfo.site_user_id, loggedInUserInfo.site_user_id, "");
        var gameId = userInfo.gameId || enginesis.gameId;
        var dayStamp = userInfo.dayStamp || sessionDayStamp();
        var siteMark = 0;

        if (userId == 0) {
            // Use the site mark only if we do not have a user id
            if (isNull(userInfo.siteMark)) {
                if (enginesis.anonymousUser) {
                    siteMark = enginesis.anonymousUser.userId;
                }
            }
        }
        var hashClear = "s=" + siteId + "&u=" + userId + "&d=" + dayStamp + "&n=" + userName + "&g=" + gameId + "&i=" + siteUserId + "&l=" + accessLevel + "&m=" + siteMark + "&k=" + enginesis.developerKey;
        var hash = md5(hashClear);
        debugLog("sessionMakeGameHash from " + hashClear + " yields " + hash);
        return hash;
    }

    /**
     * Determine if the session hash computed on the server matches the session hash computed on
     * the client. This helps us determine if the payload was tampered and a hacker is trying
     * to impersonate another user.
     * @todo: If the hash from the server doesn't match what we expected computed locally,
     * it could be someone trying to impersonate another user. It could also be that the hash
     * has expired and we just need to compute a new one.
     *
     * @param {string} hashFromServer This is the hash computed on the server, usually returned in SessionBegin.
     * @param {object|null} userInfo The user information object to validate. If null will validate against prior log in user information.
     * @returns {boolean} true if match, otherwise false.
     */
    function sessionVerifyHash(hashFromServer, userInfo) {
        return hashFromServer == sessionMakeHash(userInfo);
    }

    /**
     * Helper function to determine if we call the override function over the global function,
     * or neither if none are set.
     * @param {object} enginesisResult The enginesis service response.
     * @param {function} resolve A Promise resolve function that is always called, or null to not call a resolve function.
     * @param {function} overRideCallBackFunction if not null this function is called with enginesisResult.
     * @param {function} enginesisCallBackFunction if not null and overRideCallBackFunction was
     *   not called then this function is called with enginesisResult.
     */
    function callbackPriority(enginesisResult, resolve, overRideCallBackFunction, enginesisCallBackFunction) {
        preprocessEnginesisResult(enginesisResult)
        .then(function(updatedEnginesisResult) {
            if (overRideCallBackFunction != null) {
                overRideCallBackFunction(updatedEnginesisResult);
            } else if (enginesisCallBackFunction != null) {
                enginesisCallBackFunction(updatedEnginesisResult);
            }
            if (resolve != null) {
                resolve(updatedEnginesisResult);
            }
        });
    }

    /**
     * Internal function to handle completed service request and convert the JSON response to
     * an object and then invoke the call back function.
     * @param {integer} stateSequenceNumber Locate matching request id.
     * @returns {integer} The number of entries removed. 0 if no matching entry.
     */
    function removeFromServiceQueue(stateSequenceNumber) {
        var removed = 0;
        var serviceQueue = enginesis.serviceQueue;
        if (serviceQueue != null && serviceQueue.length > 0) {
            serviceQueue = serviceQueue.filter(function(item) {
                var match = item.state_seq == stateSequenceNumber;
                if (match) {
                    item.state_status = 2;
                    removed ++;
                }
                return ! match;
            });
            enginesis.serviceQueue = serviceQueue;
        }
        if (enginesis.serviceQueueRestored > 0 && removed > 0) {
            enginesis.serviceQueueRestored -= removed;
            saveServiceQueue();
        }
        return removed;
    }

    /**
     * When we go offline or are offline, save the service queue to disk in case the app
     * terminates.
     * @returns {boolean} True if successfully saved.
     */
    function saveServiceQueue() {
        saveObjectWithKey(enginesis.serviceQueueSaveKey, enginesis.serviceQueue);
        return true;
    }

    /**
     * When the app loads restore the saved service queue. Note we do not restore the
     * queue if we go back online because the queue is already in memory at the correct
     * state.
     * @returns {boolean} True if there are items on the queue to be processed.
     */
    function restoreServiceQueue() {
        var serviceQueue = loadObjectWithKey(enginesis.serviceQueueSaveKey);
        if (serviceQueue == null) {
            serviceQueue = [];
            enginesis.serviceQueueRestored = 0;
        } else {
            saveObjectWithKey(enginesis.serviceQueueSaveKey, []);
            enginesis.serviceQueueRestored = enginesis.serviceQueue.length;
        }
        enginesis.serviceQueue = serviceQueue;
        resetServiceQueue();
        return enginesis.serviceQueueRestored > 0;
    }

    /**
     * When reloading the service queue reset any pending transactions and run them again.
     * @returns {Array} A reference to the queue.
     */
    function resetServiceQueue() {
        var serviceQueue = enginesis.serviceQueue;
        var i;

        if (serviceQueue != null && serviceQueue.length > 0) {
            for (i = 0; i < serviceQueue.length; i ++) {
                serviceQueue[i].state_status = 0;
            }
        }
        return serviceQueue;
    }

    /**
     * Create a set of HTTP headers to communicate with the Enginesis server.
     * @param {object} additionalHeaders key/values to set for this request.
     * @returns {Object} HTTP header to be used on an HTTP request object.
     */
    function formatHTTPHeader(additionalHeaders) {
        // @todo: set "multipart/form" when sending files
        var httpHeaders = Object.assign(
            {
                "Accept": "application/json",
                "X-DeveloperKey": enginesis.developerKey
            },
            additionalHeaders);
        if (enginesis.authTokenWasValidated) {
            httpHeaders["Authentication"] = "Bearer " + enginesis.authToken;
        }
        return httpHeaders;
    }

    /**
     * Issue an HTTP request when running as a Node.js process.
     * `enginesis.nodeRequest` must be set separately with a compatible request module such as Axios.
     * @param {string} serviceName The Enginesis service to call.
     * @param {object} enginesisParameters Parameters required for the service, assumes this object was created or verified with serverParamObjectMake().
     * @param {function} overRideCallBackFunction Callback function to call when the request completes.
     * @return {boolean} True if a request is sent, false if the request was not sent.
     * @throws {Error} When a request module is not set.
     */
    function sendNodeRequest(serviceName, enginesisParameters, overRideCallBackFunction) {
        if (enginesis.nodeRequest == null) {
            if (typeof window.fetch !== "undefined") {
                enginesis.nodeRequest = window.fetch;
            } else {
                throw new Error("enginesis.nodeRequest is not set in the node.js environment");
            }
        }
        enginesis.nodeRequest(enginesis.siteResources.serviceURL, {
            method: "POST",
            headers: formatHTTPHeader(),
            body: new URLSearchParams(enginesisParameters)
        }).then(async function(response) {
            if (response.status != 200) {
                let errorMessage = "Service error " + response.status + " from " + enginesis.siteResources.serviceURL + ": " + requestError.toString();
                // @todo: we still need to determine if this is a server error or a network error
                // if (setOffline()) {
                //     errorMessage = "Enginesis network error encountered, assuming we're offline. " + enginesis.siteResources.serviceURL + " for " + serviceName + ": " + requestError.toString();
                // } else {
                //     errorMessage = "Enginesis is already offline, leaving this message on the queue.";
                // }
                // debugLog(errorMessage);
                serviceRequestComplete(enginesisParameters.state_seq, forceErrorResponseString(serviceName, enginesisParameters.state_seq, "OFFLINE", errorMessage, enginesisParameters), overRideCallBackFunction);
            } else {
                // depending on the response format type we should handle the response data
                let responseData;
                if (typeof enginesisParameters.response == "undefined" || enginesisParameters.response == "json") {
                    responseData = await response.json();
                } else {
                    responseData = response.body;
                }
                serviceRequestComplete(enginesisParameters.state_seq, responseData, overRideCallBackFunction);
            }
        })
        .catch(function(requestError) {
            const errorMessage = "Internal error posting to " + enginesis.siteResources.serviceURL + ": " + requestError.toString();
            debugLog(errorMessage);
            serviceRequestComplete(enginesisParameters.state_seq, forceErrorResponseString(serviceName, enginesisParameters.state_seq, "OFFLINE", errorMessage, enginesisParameters), overRideCallBackFunction);
        });
        return true;
    }

    /**
     * Return the next item on the queue.
     * @returns {object} Item to be processed.
     */
    function getNextUnprocessedMessage() {
        var serviceQueue = enginesis.serviceQueue;
        var unprocessedRequest = null;
        var enginesisRequest;
        var i;

        for (i = 0; i < serviceQueue.length; i ++) {
            enginesisRequest = serviceQueue[i];
            if (typeof enginesisRequest.state_status == "undefined" || enginesisRequest.state_status == 0) {
                enginesisRequest.state_status = 1;
                unprocessedRequest = enginesisRequest;
                break;
            }
        }
        return unprocessedRequest;
    }

    /**
     * Process the top-most message in the queue and call the provided resolve function when complete.
     * @param {function} resolve A Promise resolve function, or null if no context can be determined when the function completes.
     * @param {function} reject A Promise reject function, or null if no context can be determined when the function completes.
     */
    function processNextMessage(resolve, reject) {
        if (enginesis.isOnline && enginesis.serviceQueue.length > 0) {
            const enginesisParameters = getNextUnprocessedMessage();
            if (enginesisParameters != null) {
                const serviceName = enginesisParameters.fn;
                const overRideCallBackFunction = enginesisParameters.overRideCallBackFunction;
                let errorMessage;
    
                if (enginesis.isNodeBuild) {
                    sendNodeRequest(serviceName, enginesisParameters, function (enginesisResult) {
                        callbackPriority(enginesisResult, resolve, overRideCallBackFunction, enginesis.callBackFunction);
                    });
                } else {
                    fetch(enginesis.siteResources.serviceURL, {
                        method: "POST",
                        mode: "cors",
                        cache: "no-cache",
                        credentials: "same-origin",
                        headers: formatHTTPHeader(),
                        body: convertParamsToFormData(enginesisParameters)
                    })
                    .then(function (response) {
                        removeFromServiceQueue(enginesisParameters.state_seq);
                        if (response.status == 200) {
                            response.json().then(function (enginesisResult) {
                                let errorMessage;
                                if (enginesisResult == null) {
                                    // If Enginesis fails to return a valid object then the service must have failed, possible the response was not parsable JSON (e.g. error 500)
                                    var serverResponse = response.text();
                                    debugLog("Enginesis service error for " + serviceName + ": " + serverResponse);
                                    errorMessage = "Enginesis service while contacting Enginesis at " + enginesis.serverHost + " for " + serviceName;
                                    enginesisResult = forceErrorResponseObject(serviceName, enginesisParameters.state_seq, "SERVICE_ERROR", errorMessage, enginesisParameters);
                                } else {
                                    enginesisResult.fn = serviceName;
                                }
                                callbackPriority(enginesisResult, resolve, overRideCallBackFunction, enginesis.callBackFunction);
                            })
                            .catch(function (error) {
                                const errorMessage = "Invalid response from Enginesis at " + enginesis.serverHost + " for " + serviceName + ": " + error.toString();
                                const enginesisResult = forceErrorResponseObject(serviceName, enginesisParameters.state_seq, "SERVICE_ERROR", errorMessage, enginesisParameters);
                                debugLog(errorMessage);
                                callbackPriority(enginesisResult, resolve, overRideCallBackFunction, enginesis.callBackFunction);
                            });
                        } else {
                            const errorMessage = "Network error " + response.status + " while contacting Enginesis at " + enginesis.serverHost + " for " + serviceName;
                            const enginesisResult = forceErrorResponseObject(serviceName, enginesisParameters.state_seq, "SERVICE_ERROR", errorMessage, enginesisParameters);
                            debugLog(errorMessage);
                            callbackPriority(enginesisResult, resolve, overRideCallBackFunction, enginesis.callBackFunction);
                        }
                    }, function (error) {

                        // @todo: If the error is no network, then set offline and queue this request

                        if (setOffline()) {
                            errorMessage = "Enginesis Network error encountered, assuming we're offline. " + enginesis.serverHost + " for " + serviceName + ": " + error.toString();
                        } else {
                            errorMessage = "Enginesis is already offline, leaving this message on the queue.";
                        }
                        debugLog(errorMessage);
                        callbackPriority(
                            forceErrorResponseObject(serviceName, enginesisParameters.state_seq, "OFFLINE", errorMessage, enginesisParameters),
                            resolve,
                            overRideCallBackFunction,
                            enginesis.callBackFunction
                        );
                    })
                    .catch(function (error) {

                        // @todo: If the error is no network, then set offline and queue this request

                        if (setOffline()) {
                            errorMessage = "Enginesis Network error encountered, assuming we're offline. " + enginesis.serverHost + " for " + serviceName + ": " + error.toString();
                        } else {
                            errorMessage = "Enginesis is already offline, leaving this message on the queue.";
                        }
                        debugLog(errorMessage);
                        callbackPriority(
                            forceErrorResponseObject(serviceName, enginesisParameters.state_seq, "OFFLINE", errorMessage, enginesisParameters),
                            resolve,
                            overRideCallBackFunction,
                            enginesis.callBackFunction
                        );
                    });
                }
            } else {
                if (reject != null) {
                    reject(new Error("Queue is empty"));
                }
            }
        } else {
            if (reject != null) {
                reject(new Error("Offline or queue is empty"));
            }
        }
    }

    /**
     * Internal function to send a service request to the server.
     * @param {string} serviceName Which service endpoint to call.
     * @param {object} parameters Key/value pairs for all parameters to send.
     * @param {function} overRideCallBackFunction Optional function to call when service request completes.
     * @returns {Promise} A promise object is returned that resolves when the service request completes.
     */
    function sendRequest(serviceName, parameters, overRideCallBackFunction) {
        return new Promise(function(resolve, reject) {
            if ( ! enginesis.disabled && isValidOperationalState()) {
                const enginesisParameters = serverParamObjectMake(serviceName, parameters);
                enginesisParameters.overRideCallBackFunction = overRideCallBackFunction;
                enginesis.serviceQueue.push(enginesisParameters);
                if (enginesis.isOnline) {
                    processNextMessage(resolve, reject);
                } else {
                    const errorMessage = "Enginesis is offline. Message " + serviceName + " will be processed when network connectivity is restored.";
                    const enginesisResult = forceErrorResponseObject(serviceName, enginesisParameters.state_seq, "OFFLINE", errorMessage, parameters);
                    saveServiceQueue();
                    debugLog(errorMessage);
                    callbackPriority(enginesisResult, resolve, overRideCallBackFunction, enginesis.callBackFunction);
                }
            } else {
                let enginesisResult;
                if (enginesis.disabled) {
                    enginesisResult = forceErrorResponseObject(serviceName, 0, "DISABLED", "Enginesis is disabled.", parameters);
                } else {
                    enginesisResult = forceErrorResponseObject(serviceName, 0, "VALIDATION_FAILED", "Enginesis internal state failed validation.", parameters);
                }
                callbackPriority(enginesisResult, resolve, overRideCallBackFunction, enginesis.callBackFunction);
            }
        });
    }

    /**
     * When a process fails on the client, we don't need to send a request to the server. In order
     * to keep the process flow, send back an error that wll immediately resolve to a proper
     * EnginesisResult with the error information.
     *
     * @param {string} serviceName Enginesis service name.
     * @param {object} parameters Parameters sent to server.
     * @param {string} errorCode Enginesis error code to send.
     * @param {string} errorMessage Additional error information.
     * @param {function} overRideCallBackFunction Function to call with result.
     * @returns {Promise} A promise that will resolve with the EnginesisResult as an error response.
     */
    function immediateErrorResponse(serviceName, parameters, errorCode, errorMessage, overRideCallBackFunction) {
        return new Promise(function(resolve) {
            const enginesisResult = forceErrorResponseObject(serviceName, parameters.state_seq || 0, errorCode, errorMessage, parameters);
            callbackPriority(enginesisResult, resolve, overRideCallBackFunction, enginesis.callBackFunction);
        });
    }

    /**
     * Internal function to make a parameter object complementing a service request. Depending on the
     * current state of the system specific internal variables are appended to the service request.
     * @param {string} serviceName Enginesis service endpoint.
     * @param {object} additionalParameters Key/value pairs of parameters and their respective values.
     * @returns {object} An object to be used in an Enginesis service request.
     */
    function serverParamObjectMake (serviceName, additionalParameters) {
        enginesis.internalStateSeq += 1;
        // these are defaults that could be overridden with additionalParameters
        var serverParams = {
            fn: serviceName,
            language_code: enginesis.languageCode,
            site_id: enginesis.siteId,
            state_seq: enginesis.internalStateSeq,
            state_status: 0,
            response: "json"
        };
        if (enginesis.loggedInUserInfo && enginesis.authTokenWasValidated && Math.floor(enginesis.loggedInUserInfo.user_id) != 0) {
            serverParams.authtok = enginesis.authToken;
            if (serviceName == "SessionRefresh") {
                serverParams.logged_in_user_id = enginesis.loggedInUserInfo.user_id;
            }
        }
        if (enginesis.gameId) {
            serverParams.game_id = enginesis.gameId;
        }
        if (additionalParameters != null) {
            for (var key in additionalParameters) {
                if (additionalParameters.hasOwnProperty(key)) {
                    serverParams[key] = additionalParameters[key];
                }
            }
        }
        return serverParams;
    }

    /**
     * Generate an internal error that looks the same as an error response from the server.
     * @param {string} serviceName The official Enginesis service endpoint that was invoked.
     * @param {integer} stateSeq Session serial number.
     * @param {string} errorCode An Enginesis error code.
     * @param {string} errorMessage Additional info about the error, such as data conditions.
     * @param {object} passThrough Object of parameters supplied to the service endpoint.
     * @returns {string} a JSON string representing a standard Enginesis error.
     */
    function forceErrorResponseString(serviceName, stateSeq, errorCode, errorMessage, passThrough) {
        return JSON.stringify(forceErrorResponseObject(serviceName, stateSeq, errorCode, errorMessage, passThrough));
    }

    /**
     * Generate an internal error that looks the same as an error response from the server.
     * @param {string} serviceName The official Enginesis service endpoint that was invoked.
     * @param {integer} sequenceNumber Session serial number.
     * @param {string} errorCode An Enginesis error code.
     * @param {string} errorMessage Additional info about the error, such as data conditions.
     * @param {object} passThrough Object of parameters supplied to the service endpoint.
     * @returns {object} the Enginesis error object.
     */
    function forceErrorResponseObject(serviceName, sequenceNumber, errorCode, errorMessage, passThrough) {
        if (typeof serviceName === "undefined" || serviceName === null || serviceName == "") {
            serviceName = "unknown";
        }
        if (typeof sequenceNumber === "undefined" || sequenceNumber == null) {
            sequenceNumber = 0;
        }
        if (typeof passThrough === "undefined" || passThrough == null) {
            passThrough = {};
        }
        if (typeof passThrough.fn === "undefined" || passThrough.fn == null) {
            passThrough.fn = serviceName;
        }
        if (typeof passThrough.state_seq === "undefined" || passThrough.state_seq == null) {
            passThrough.state_seq = sequenceNumber;
        }
        const isError = ! (errorCode == "" || errorCode == "NO_ERROR");
        return {
            fn: serviceName,
            results: {
                result: [],
                status: {
                    success: isError ? "0" : "1",
                    message: errorCode,
                    extended_info: errorMessage
                },
                passthru: passThrough
            }
        };
    }

    /**
     * Convert a parameter object to a proper HTTP Form request.
     * @param {object} parameterObject The object to convert.
     * @returns {FormData} Form data object to be used in HTTP request.
     */
    function convertParamsToFormData (parameterObject) {
        var key;
        var formDataObject;

        if (enginesis.isBrowserBuild) {
            formDataObject = new FormData();
        } else {
            formDataObject = {};
        }
        for (key in parameterObject) {
            if (parameterObject.hasOwnProperty(key) && typeof parameterObject[key] !== "function" && key != "overRideCallBackFunction") {
                if (enginesis.isBrowserBuild) {
                    formDataObject.append(key, parameterObject[key]);
                } else {
                    formDataObject[key] = parameterObject[key];
                }
            }
        }
        return formDataObject;
    }

    /**
     * When Enginesis is offline all messages are queued.
     * @returns {boolean} True if set offline, otherwise false for online.
     */
    function setOffline() {
        var fromOnlineToOffline;
        if (enginesis.isOnline) {
            saveServiceQueue();
            fromOnlineToOffline = true;
        } else {
            fromOnlineToOffline = false;
        }
        enginesis.isOnline = false;
        return fromOnlineToOffline;
    }

    /**
     * When network connectivity is restored process all messages in the queue.
     * @returns {Promise} Resolve is called once all items in the queue are complete, or we go back offline.
     */
    function restoreOnline() {
        const wasOffline = ! enginesis.isOnline;
        enginesis.isOnline = true;

        function processNextIfQueueNotEmpty(resolve) {
            if (enginesis.isOnline && enginesis.serviceQueue.length > 0) {
                if (wasOffline) {
                    // @todo: we were offline but now we are back online, should we generate an event to alert the app?
                }
                processNextMessage(function() {
                    processNextIfQueueNotEmpty(resolve);
                }, function() {
                    processNextIfQueueNotEmpty(resolve);
                });
            } else {
                if (wasOffline) {
                    // @todo: we were offline and we're still offline.
                }
                resolve();
            }
        }

        restoreServiceQueue();
        return new Promise(function(resolve) {
            processNextIfQueueNotEmpty(resolve);
        });
    }

    /**
     * Set the internal https protocol flag based on the current page we are loaded on. There
     * is a special case for when we are running in Node with Jest.
     */
    function setProtocolFromCurrentLocation () {
        if (enginesis.isBrowserBuild && ! enginesis.isNodeBuild) {
            enginesis.useHTTPS = window.location.protocol == "https:";
        } else {
            enginesis.useHTTPS = true;
        }
        return enginesis.useHTTPS;
    }

    /**
     * Return the proper protocol based on our internal HTTPS setting.
     * @returns {string}
     */
    function getProtocol() {
        return enginesis.useHTTPS ? "https://" : "http://";
    }

    /**
     * Set the server stage we will converse with using some simple heuristics.
     * @param {string} newServerStage Server stage to communicate with.
     * @returns {string} The server stage that was set.
     */
    function qualifyAndSetServerStage (newServerStage) {
        var regMatch;
        var currentHost = enginesis.isBrowserBuild ? window.location.host : "enginesis-l.com"; // @todo: How to get host in NodeJS?
        var isLocalhost = false;
        enginesis.serverHost = null;

        if (newServerStage === undefined || newServerStage === null) {
            // if a stage is not request then match the current stage
            newServerStage = "*";
        }
        switch (newServerStage) {
            case "":
            case "-l":
            case "-d":
            case "-q":
            case "-x":
                // use the stage requested
                enginesis.serverStage = newServerStage;
                break;
            case "*":
                // match the stage matching current host
                if (currentHost.substring(0, 9) == "localhost") {
                    newServerStage = "-l";
                    isLocalhost = true;
                } else {
                    regMatch = /-[ldqx]\./.exec(currentHost);
                    if (regMatch != null && regMatch.index > 0) {
                        newServerStage = currentHost.substring(regMatch.index, regMatch.index + 2);
                    } else {
                        // anything we do not expect goes to the live instance
                        newServerStage = "";
                    }
                }
                enginesis.serverStage = newServerStage;
                break;
            default:
                // if it was not a stage match assume it is a full host name, find the stage in it if it exists
                regMatch = /-[ldqx]\./.exec(newServerStage);
                if (regMatch != null && regMatch.index > 0) {
                    enginesis.serverStage = newServerStage.substring(regMatch.index, regMatch.index + 2);
                } else {
                    // anything we do not expect goes to the live instance
                    enginesis.serverStage = "";
                }
                // use the domain requested
                enginesis.serverHost = newServerStage;
                break;
        }
        if (enginesis.serverHost === null) {
            // convert www.host.tld into enginesis.host.tld
            var service = "enginesis";
            var domainParts = currentHost.split(".");
            var numberOfParts = domainParts.length;
            var host;

            if (numberOfParts > 1) {
                host = domainParts[numberOfParts - 2].replace(/-[ldqx]$/, "");
                if (host != service) {
                    enginesis.serverHost = service + ".";
                } else {
                    enginesis.serverHost = "";
                }
                enginesis.serverHost += host
                    + enginesis.serverStage
                    + "." + domainParts[numberOfParts - 1];
            } else if (isLocalhost) {
                enginesis.serverHost = "enginesis-l.com";
            } else {
                enginesis.serverHost = currentHost;
            }
        }
        enginesis.siteResources.serviceURL = getProtocol() + enginesis.serverHost + "/index.php";
        enginesis.siteResources.avatarImageURL = getProtocol() + enginesis.serverHost + "/avatar/index.php";
        enginesis.siteResources.assetUploadURL = getProtocol() + enginesis.serverHost + "/procs/asset.php";
        return enginesis.serverStage;
    }

    /**
     * Determine if the device we are running on is considered a touch interface.
     * @returns {boolean} true if touch available, false if not.
     */
    function touchDevice () {
        var isTouch = false;
        if (enginesis.isBrowserBuild) {
            if ("ontouchstart" in window) {
                isTouch = true;
            } else if (window.DocumentTouch && document instanceof DocumentTouch) {
                isTouch = true;
            }
        }
        return isTouch;
    }

    /**
     * Cache settings regarding the current platform we are running on.
     */
    function setPlatform () {
        if (enginesis.isBrowserBuild) {
            enginesis.platform = "browser";
            enginesis.locale = navigator.language;
            enginesis.isNativeBuild = window.location.protocol == "file:";
            enginesis.isTouchDeviceFlag = touchDevice();
        } else {
            enginesis.platform = "nodejs";
            enginesis.locale = "en";
            enginesis.isNativeBuild = true;
            enginesis.isTouchDeviceFlag = false;
        }
    }

    /**
     * Set the language code for Enginesis error messages and service responses.
     * @param {string} languageCode 2-letter language code, e.g. "en".
     */
    function setLanguageCode(languageCode) {
        if (isEmpty(languageCode)) {
            languageCode = "en";
        } else if (languageCode.length > 2) {
            languageCode = languageCode.substring(0, 2);
        }
        return languageCode;
    }

    /**
     * Return the current document query string as an object with
     * key/value pairs converted to properties.
     * @param {string} urlParameterString An optional query string to parse as the query string. If not
     *   provided then use window.location.search.
     * @return {object} result The query string converted to an object of key/value pairs.
     */
    function queryStringToObject (urlParameterString) {
        const search = /([^&=]+)=?([^&]*)/g;
        let match;
        let result = {};
        let queryString;
        function decode(s) {
            return decodeURIComponent(s.replace(/\+/g, " "));
        }
    
        if ( ! urlParameterString && enginesis.isBrowserBuild) {
            queryString = window.location.search.substring(1);
        } else {
            queryString = urlParameterString;
        }
        if (queryString[0] == "?") {
            queryString = queryString.substring(1);
        }
        while (match = search.exec(queryString)) {
            result[decode(match[1])] = decode(match[2]);
        }
        return result;
    }

    /**
     * Return the contents of the cookie indexed by the specified key.
     * @param {string} key Indicate which cookie to get.
     * @returns {string} Contents of cookie stored with key.
     */
    function cookieGet (key) {
        if (typeof window.document !== "undefined" && key) {
            return decodeURIComponent(window.document.cookie.replace(new RegExp("(?:(?:^|.*;)\\s*" + encodeURIComponent(key).replace(/[\-\.\+\*]/g, "\\$&") + "\\s*\\=\\s*([^;]*).*$)|^.*$"), "$1")) || null;
        } else {
            return null;
        }
    }

    /**
     * Set a cookie indexed by the specified key.
     * @param {string} key Indicate which cookie to set.
     * @param {object} value Value to store under key. If null, expire the prior cookie.
     * @param {Number|String|Date} expiration When the cookie should expire. Number indicates
     *   max age, in seconds. String indicates GMT date. Date is converted to GMT date.
     * @param {string} path Cookie URL path.
     * @param {string} domain Cookie domain.
     * @param {boolean} isSecure Set cookie secure flag. Default is true.
     * @return {boolean|string} true if set, false if error. Returns string if not running in
     *   a browser environment, such as Node.
     */
    function cookieSet (key, value, expiration, path, domain, isSecure) {
        var expires;
        var neverExpires;
        var sameSite;
        var cookieData;

        if ( ! key || /^(?:expires|max\-age|path|domain|secure)$/i.test(key)) {
            // This is an invalid cookie key.
            return false;
        }
        if (value === null || typeof value === "undefined") {
            // remove the cookie by expiring it
            cookieData = "; expires=Thu, 01 Jan 1970 00:00:00 GMT" + (domain ? "; domain=" + domain : "") + (path ? "; path=" + path : "");
        } else {
            expires = "";
            neverExpires = "expires=Fri, 31 Dec 9999 23:59:59 GMT";
            sameSite = "SameSite=LAX";
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
            cookieData = encodeURIComponent(value) + "; "
                + expires + "; "
                + (domain ? ("domain=" + domain + "; ") : "")
                + (path ? ("path=" + path + "; ") : "")
                + sameSite + "; "
                + (isSecure ? "Secure;" : "");
        }
        if (typeof window.document === "undefined") {
            // If the document object is undefined then we are running in Node.
            return cookieData;
        }
        window.document.cookie = encodeURIComponent(key) + "=" + cookieData;
        return true;
    };

    /**
     * Get info about the current logged in user, if there is one, from authtok parameter or cookie.
     * The authentication token can be provided to the game via query string (authtok=xxx) or
     * stored in a HTTP session cookie. The priority logic is:
     *   1. use authToken provided as a parameter to `enginesis.init()`
     *   2. else, use authtok provided as a query to the current page
     *   3. else, use authToken saved in enginesis session cookie
     * @param {string} authToken can be specified if one is being passed around, but it still requires validation.
     * @returns {boolean} true if a user is restored this way, false if not.
     */
    function restoreUserFromAuthToken (authToken) {
        var queryParameters;
        var wasRestored = false;
        var loggedInUserInfo = null;

        if (isEmpty(authToken)) {
            // if a token was not provided, try to find it in a cache in the following order:
            // 1. from server-supplied cookie
            // 2. from query string parameter
            // 3. in local storage from prior session
            authToken = cookieGet(enginesis.SESSION_COOKIE);
            if (isEmpty(authToken)) {
                queryParameters = queryStringToObject();
                if (queryParameters.authtok !== undefined) {
                    authToken = queryParameters.authtok;
                    debugLog("restoreUserFromAuthToken from query: " + authToken);
                }
                if (isEmpty(authToken)) {
                    loggedInUserInfo = loadObjectWithKey(enginesis.SESSION_USERINFO);
                    if (loggedInUserInfo != null && loggedInUserInfo.authToken) {
                        authToken = loggedInUserInfo.authToken;
                        debugLog("restoreUserFromAuthToken from prior session: " + authToken);
                    }
                }
            }
        } else {
            debugLog("restoreUserFromAuthToken from parameter: " + authToken);
        }
        if ( ! isEmpty(authToken)) {
            // @todo: Validate the token (for now we are accepting that it is valid but we should check!) If the authToken is valid then we can trust the userInfo
            // @todo: we can use cr to validate the token was not changed
            if (loggedInUserInfo == null) {
                loggedInUserInfo = JSON.parse(cookieGet(enginesis.SESSION_USERINFO));
                debugLog("restoreUserFromAuthToken user info from session cookie: " + loggedInUserInfo);
                if (loggedInUserInfo == null) {
                    loggedInUserInfo = loadObjectWithKey(enginesis.SESSION_USERINFO);
                    debugLog("restoreUserFromAuthToken user info from local storage: " + loggedInUserInfo);
                }
            }
            if (loggedInUserInfo != null) {
                enginesis.authToken = authToken;
                enginesis.authTokenExpires = null; // @todo: Need to get the expiry of this token.
                enginesis.authTokenWasValidated = true; // @todo: we should verify this payload is valid.
                enginesis.loggedInUserInfo = loggedInUserInfo;
                enginesis.networkId = Math.floor(loggedInUserInfo.network_id);
                wasRestored = true;
                debugLog("restoreUserFromAuthToken valid user: " + enginesis.loggedInUserInfo.user_name + "(" + enginesis.loggedInUserInfo.user_id + ")");
            } else {
                // if we have an authtoken but we did not cache the user info, then
                // if we trust that token, we need to log this user in
                debugLog("restoreUserFromAuthToken valid token but no cached user " + authToken);
            }
        }
        return wasRestored;
    }

    /**
     * Remove the local cache of user info.
     */
    function clearUserSessionInfo() {
        removeObjectWithKey(enginesis.SESSION_USERINFO);
        _clearRefreshToken();
        cookieSet(enginesis.SESSION_USERINFO, null, 0, "/", "", true);
        initializeLocalSessionInfo();
    }

    /**
     * Once a user logs in successfully we save the important data in a local cache so we can
     * restore the session between game loads. If the session expires we can use a session
     * refresh instead of asking the user to log in again.
     * @param {Object|null} sessionInfo the parameters that define the user session, otherwise saves
     *   what is already set on the current session.
     *   sessionInfo.expires is a UTC date when this info should expire.
     * @param {boolean} fromGameSession True if sessionInfo was derived from a game session (SessionBegin), false if it is from a user session (UserLogin)
     * @returns {boolean} true if the save was successful, otherwise false.
     */
    function saveUserSessionInfo(sessionInfo, fromGameSession) {
        var haveValidSession;
        if (sessionInfo) {
            if (fromGameSession) {
                haveValidSession = sessionVerifyGameHash(sessionInfo);
            } else {
                haveValidSession = sessionVerifyHash(sessionInfo.cr, enginesis.loggedInUserInfo);
            }
            if ( ! haveValidSession) {
                var hash;
                if (fromGameSession) {
                    hash = sessionMakeGameHash(enginesis.loggedInUserInfo);
                } else {
                    hash = sessionMakeHash(enginesis.loggedInUserInfo);
                }
                debugLog("Possible payload compromise: provided hash " + sessionInfo.cr + " does not match computer here " + hash);
                // @todo: What action to take if hash does not agree?
                haveValidSession = false;
            }
            haveValidSession = true;
            enginesis.sessionId = sessionInfo.session_id;
            enginesis.sessionExpires = Date.now() + (24 * 60 * 60 * 1000); // have it expire in 1 day.
            enginesis.authToken = sessionInfo.authToken || sessionInfo.authtok;
            // enginesis.authTokenExpires = new Date(sessionInfo.expires);
            enginesis.loggedInUserInfo.authToken = enginesis.authToken;
            enginesis.loggedInUserInfo.authTokenExpires = sessionInfo.expires;
            enginesis.authTokenWasValidated = true;
            if (sessionInfo.refresh_token) {
                enginesis.refreshToken = sessionInfo.refresh_token;
                enginesis.refreshTokenExpires = new Date(sessionInfo.expires);
            }
            saveObjectWithKey(enginesis.SESSION_USERINFO, enginesis.loggedInUserInfo);
            debugLog("enginesis.saveUserSessionInfo session id is " + enginesis.sessionId + " session.cr= " + sessionInfo.cr);
        } else {
            haveValidSession = false;
        }
        return haveValidSession;
    }

    /**
     * Restore a prior user session if one can be determined. If an Enginesis authentication token is provided,
     * use it to validate the user. If the token is not provided or it is not valid, attempt to use a prior
     * local storage or browser cookie.
     * @param {string} authToken If an Enginesis authentication token is provided use it to validate the user.
     */
    function restoreUserSession(authToken) {
        if ( ! restoreUserFromAuthToken(authToken)) {
            restoreUserSessionInfo();
        }
        if ( ! enginesis.isUserLoggedIn) {
            anonymousUserLoad();
        }
    }

    /**
     * When reloading the game we can see if a prior user login was in the cache so we can
     * restore the session. If the session expires we can use a session refresh instead of
     * asking the user to log in again.
     * @returns {boolean} true if the save was successful, otherwise false.
     */
    function restoreUserSessionInfo() {
        var hash;
        var success = false;
        var userInfoSaved = loadObjectWithKey(enginesis.SESSION_USERINFO);
        if (userInfoSaved == null) {
            userInfoSaved = cookieGet(enginesis.SESSION_USERINFO);
            if (userInfoSaved != null) {
                try {
                    userInfoSaved = JSON.parse(userInfoSaved);
                } catch (exception) {
                    userInfoSaved = null;
                    clearUserSessionInfo();
                }
            }
        }
        if (userInfoSaved != null) {
            hash = sessionMakeHash({
                siteId: enginesis.siteId,
                userId: userInfoSaved.userId,
                userName: userInfoSaved.userName,
                siteUserId: userInfoSaved.siteUserId || "",
                accessLevel: userInfoSaved.accessLevel,
                siteKey: enginesis.developerKey
            });
            // @todo: verify hash to verify the payload was not tampered.
            // @todo: verify session, authtok, but if expired try to refresh the session.
            if (hash != userInfoSaved.cr) {
                debugLog("restoreUserSessionInfo hash does not match. From server: " + userInfoSaved.cr + ". Computed here: " + hash);
            }
            enginesis.loggedInUserInfo = userInfoSaved;
            if (isEmpty(userInfoSaved.session_id)) {
                debugLog("*** enginesis.restoreUserSessionInfo unexpected server response from " + JSON.stringify(userInfoSaved));
            }
            enginesis.networkId = userInfoSaved.network_id;
            enginesis.sessionId = userInfoSaved.session_id;
            enginesis.sessionExpires = new Date(userInfoSaved.session_expires);
            enginesis.authToken = userInfoSaved.authToken;
            enginesis.authTokenExpires = new Date(userInfoSaved.session_expires);
            enginesis.authTokenWasValidated = true; // @todo: We should actually validate it (check expired, check hash, verify user_id matches)
            enginesis.refreshToken = userInfoSaved.refresh_token;
            enginesis.refreshTokenExpires = new Date(userInfoSaved.expires);
            enginesis.isUserLoggedIn = isUserLoggedIn();
            debugLog("enginesis.restoreUserSessionInfo " + enginesis.sessionId + " from " + JSON.stringify(userInfoSaved));
        } else if (enginesis.isUserLoggedIn) {
            // if a user was not cached and we trust the authtok then we need to load this user
            enginesis.isUserLoggedIn = isUserLoggedIn();
            debugLog("enginesis.restoreUserSessionInfo we think the user is logged in but wasn't cached");
        }
        return success;
    }

    /**
     * Verify the information we have on this user matches what we cached from the server (in case
     * a hacker compromised what's in memory.) Verify the authentication token has not expired. If
     * it has we need to request a new one, which will require a trip to the server and take time.
     * This is something that should be called before any sensitive transaction with the server.
     * Granted, the server will still do these checks, but doing them here saves server resources
     * and user frustration.
     * @returns {Promise} Returns a Promise that will resolve if the session is not expired, or if
     *   the session has expired then once a new session is established with the server. The new session
     *   will automatically update the local cache and Enginesis internal state. This will reject if
     *   the session cannot be refreshed, in which case the user must log in.
     */
    function verifyUserSessionInfo() {
        return new Promise(function(resolve, reject) {
            var hash;
            var errorMessage;
            var sessionExpireTime;
            var sessionExpired;
            var timeZoneOffset;
            var isRefreshed = false;
            var hashMatched = false;
            var loggedInUserInfo = enginesis.loggedInUserInfo;
            var userInfoSaved = loadObjectWithKey(enginesis.SESSION_COOKIE);
            if (userInfoSaved != null) {
                if ( ! userInfoSaved.sessionExpires) {
                    // if we don't get a session expire date then just assume it expired.
                    sessionExpireTime = new Date();
                } else {
                    sessionExpireTime = new Date(userInfoSaved.sessionExpires);
                }
                timeZoneOffset = sessionExpireTime.getTimezoneOffset() * 60000;
                sessionExpired = Date.now().valueOf() > (sessionExpireTime.valueOf() - timeZoneOffset);
                hash = sessionMakeHash({
                    siteId: enginesis.siteId,
                    userId: loggedInUserInfo.user_id,
                    userName: loggedInUserInfo.user_name,
                    siteUserId: loggedInUserInfo.site_user_id || "",
                    accessLevel: loggedInUserInfo.access_level,
                    siteKey: enginesis.developerKey
                });
                hashMatched = (hash == userInfoSaved.cr) && (Math.floor(loggedInUserInfo.user_id) == Math.floor(userInfoSaved.user_id));
                if ( ! sessionExpired && hashMatched) {
                    isRefreshed = true;
                    resolve(isRefreshed);
                } else {
                    if (sessionExpired) {
                        debugLog("verifyUserSessionInfo Session expired but we think we can refresh it.");
                        enginesis.sessionRefresh(_getRefreshToken(), null)
                        .then(function() {
                            isRefreshed = true;
                            resolve(isRefreshed);
                        }, function(enginesisError) {
                            reject(enginesisError);
                        })
                        .catch(function(exception) {
                            reject(exception);
                        });
                    } else {
                        errorMessage = "Session hash does not match but session not expired.";
                        debugLog("verifyUserSessionInfo " + errorMessage + " from cache: " + userInfoSaved.cr + ". Computed here: " + hash);
                        reject(new Error(errorMessage));
                    }
                }
            }
        });
    }

    /**
     * Save a refresh token in local storage. We use this token to refresh a login if we
     * have a logged in user but the authentication token expired.
     * @param {string} refreshToken Refresh token to save.
     */
    function _saveRefreshToken(refreshToken) {
        if ( ! isEmpty(refreshToken)) {
            var refreshTokenData = {
                    refreshToken: refreshToken,
                    timestamp: new Date().getTime()
                };
            saveObjectWithKey(enginesis.refreshTokenStorageKey, refreshTokenData);
        }
    }

    /**
     * Recall a refresh token in local storage.
     * @returns {string} either the token that was saved or null.
     */
    function _getRefreshToken() {
        var refreshToken = enginesis.refreshToken;
        if (isEmpty(refreshToken)) {
            restoreUserSessionInfo();
            refreshToken = enginesis.refreshToken;
            if (isEmpty(refreshToken)) {
                refreshToken = null;
            }
        }
        return refreshToken;
    }

    /**
     * Remove a refresh token in local storage.
     */
    function _clearRefreshToken() {
        removeObjectWithKey(enginesis.refreshTokenStorageKey);
    }

    /**
     * Initialize the anonymous user data.
     * @returns {object} The user data object.
     */
    function anonymousUserInitialize() {
        return {
            dateCreated: new Date(),
            dateLastVisit: new Date(),
            subscriberEmail: "",
            userId: 0,
            userName: "",
            favoriteGames: null,
            gamesPlayed: new Set(),
            cr: ""
        };
    }

    /**
     * Load the anonymous user data from local storage. If we do not have a prior save then initialize
     * a first time user.
     * @returns {object} The user data object.
     */
    function anonymousUserLoad() {
        if (enginesis.anonymousUser == null) {
            enginesis.anonymousUser = loadObjectWithKey(enginesis.anonymousUserKey);
            if (enginesis.anonymousUser == null) {
                enginesis.anonymousUser = anonymousUserInitialize();
            } else {
                var cr = enginesis.anonymousUser.cr || "";
                if (cr != anonymousUserHash()) {
                    enginesis.anonymousUser = anonymousUserInitialize();
                }
                if (Array.isArray(enginesis.anonymousUser.favoriteGames)) {
                    enginesis.favoriteGames = new Set(enginesis.anonymousUser.favoriteGames);
                    enginesis.anonymousUser.favoriteGames = null;
                }
                if (Array.isArray(enginesis.anonymousUser.gamesPlayed)) {
                    enginesis.anonymousUser.gamesPlayed = new Set(enginesis.anonymousUser.gamesPlayed);
                }
            }
        }
        return enginesis.anonymousUser;
    }

    /**
     * Save the anonymous user to local storage. The Sets are converted to Arrays for serialization.
     */
    function anonymousUserSave() {
        if (enginesis.anonymousUser != null) {
            var anonymousUser = enginesis.anonymousUser;
            anonymousUser.favoriteGames = Array.from(enginesis.favoriteGames);
            anonymousUser.gamesPlayed = Array.from(anonymousUser.gamesPlayed);
            anonymousUser.cr = anonymousUserHash();
            saveObjectWithKey(enginesis.anonymousUserKey, anonymousUser);
            anonymousUser.favoriteGames = null;
            anonymousUser.gamesPlayed = new Set(anonymousUser.gamesPlayed);
        }
    }

    /**
     * Create a hash for the anonymous user data object.
     * @returns {string} Hash for anonymous user data.
     */
    function anonymousUserHash() {
        var anonymousUser = enginesis.anonymousUser;
        return md5(anonymousUser.subscriberEmail + anonymousUser.userId + anonymousUser.userName + enginesis.developerKey);
    }

    /**
     * Prepare a score submission to be sent securely to the server. This is an internal function and
     * not designed to be called by client code.
     * @param {integer} siteId Site identifier.
     * @param {integer} userId User who is submitting the score.
     * @param {integer} gameId Game that was played.
     * @param {integer} score Game final score.
     * @param {string} gameData JSON string of game-specific play data.
     * @param {integer} timePlayed Game play time related to score and gameData, in milliseconds.
     * @param {string} sessionId Session id that was given at SessionBegin.
     * @returns {string} the encrypted score payload or null if an error occurred.
     */
    function encryptScoreSubmit(siteId, userId, gameId, score, gameData, timePlayed, sessionId) {
        var result = null;
        var rawScoreString = "site_id=" + siteId.toString() + "&user_id=" + userId.toString() + "&game_id=" + gameId.toString() + "&score=" + score.toString() + "&game_data=" + gameData + "&time_played=" + timePlayed.toString();
        console.log("Encrypting with " + sessionId + " data " + rawScoreString);
        result = blowfishWrapper.encryptString(rawScoreString, sessionId);
        return result;
    }

    /**
     * Request the upload of a file to a specific Enginesis service identified by `requestType`. See Enginesis documentation
     * regarding the request types. There are two options for indicating the file contents:
     * 1. Provide the blob of data with `fileData` and specify a file name without any path information.
     * 2. Provide null for `fileData` and provide a full path specification to a file on disk that the app can get read access to.
     * This function makes the request to the server and in response either gets a token or an error. If the token is received then
     * the file can be uploaded with _completeFileUpload().
     *
     * @param {string} requestType In order to send a file to the server you must indicate the type of service.
     * @param {string} fileName The full path and name of the file to upload.
     * @param {ArrayBuffer|null} fileData The file data.
     * @return {Promise} Call the Promise.resolve function when a valid reply comes back from the server, all other conditions call the Promise.reject function.
     */
    function _requestFileUpload(requestType, fileName, fileData) {
        return new Promise(function(resolve, reject) {
            if (enginesis.assetUploadQueue == null) {
                enginesis.assetUploadQueue = [];
            }
            var errorMessage = "";
            var errorCode = "";
            var uploadAttributes = {
                target: requestType,
                token: null,
                uploadId: 0,
                fileName: fileName,
                fileSize: fileData.length,
                serverURL: '',
                uploadTime: Date.now()
            };
            var parameters = {
                site_id: enginesis.siteId,
                action: "request",
                target: uploadAttributes.target,
                file: uploadAttributes.fileName,
                size: uploadAttributes.fileSize,
                game_id: enginesis.gameId
            }
            var fetchOptions = {
                method: "POST",
                mode: "cors",
                credentials: "same-origin",
                cache: 'default',
                headers: formatHTTPHeader(),
                body: convertParamsToFormData(parameters)
            };
            fetch(enginesis.siteResources.assetUploadURL, fetchOptions)
            .then(function (response) {
                if (response && response.ok) {
                    var contentType = response.headers.get("content-type");
                    if (contentType && contentType.includes("application/json")) {
                        response.json().then(function(enginesisResponse) {
                            // if response is good, add to queue then schedule follow up to do the upload.
                            if (enginesisResponse != null) {
                                if (enginesisResponse.status && enginesisResponse.status.success == "1" && enginesisResponse.results) {
                                    uploadAttributes.token = enginesisResponse.results.token;
                                    uploadAttributes.uploadId = enginesisResponse.results.id;
                                    enginesis.assetUploadQueue.push(uploadAttributes);
                                    _completeFileUpload(uploadAttributes, fileData)
                                    .then(function(enginesisResponse) {
                                        resolve(enginesisResponse);
                                    }, function (enginesisResponse) {
                                        resolve(enginesisResponse);
                                    })
                                    .catch(function(exception) {
                                        errorCode = "SERVICE_ERROR";
                                        errorMessage = "Error: " + exception.toString() + " Received from service with " + fileName + " with size " + fileSize + ".";
                                    });
                                } else {
                                    errorCode = "SERVICE_ERROR";
                                    errorMessage = "Error: " + enginesisResponse.status.extended_info + " Received from service with " + fileName + " with size " + fileSize + ".";
                                }
                            } else {
                                errorCode = "SERVICE_ERROR";
                                errorMessage = "There was a service error during the upload operation. The support team has been notified.";
                            }
                        })
                        .catch(function(jsonParseException) {
                            errorCode = "SERVICE_ERROR";
                            errorMessage = "Unexpected response received from service: " + jsonParseException.toString();
                        });
                    } else {
                        errorCode = "SERVICE_ERROR";
                        errorMessage = "Unexpected response received from service when requesting upload token.";
                    }
                } else {
                    errorCode = "SERVICE_ERROR";
                    errorMessage = "Error received from service when requesting upload token.";
                }
                if (errorCode != "") {
                    reject(makeErrorResponse(errorCode, errorMessage, parameters));
                }
            }, function (error) {
                errorCode = "SERVICE_ERROR";
                errorMessage = "Network error from service when requesting token. " + error.toString();
                reject(makeErrorResponse(errorCode, errorMessage, parameters));
            }).catch(function (exception) {
                errorCode = "SERVICE_ERROR";
                errorMessage = "Unexpected response received from service when requesting token with " + exception.toString() + ".";
                reject(makeErrorResponse(errorCode, errorMessage, parameters));
            });
        });
    }

    /**
     * Complete an Enginesis asset file upload when a request was previously made and an upload token
     * has been granted. Parameter `uploadAttributes` holds the attributes of the in-progress upload,
     * such as target, fileName, fileSize, token, uploadId, and others.
     *
     * @param {Object} uploadAttributes The attributes of a file upload request that is in progress.
     * @param {Blob} fileData The data of the file that is to be sent to the server.
     * @return {Promise} A promise is returned that resolves once the file upload is complete.
     */
    function _completeFileUpload(uploadAttributes, fileData) {
        return new Promise(function(resolve, reject) {
            var parameters = {
                site_id: enginesis.siteId,
                game_id: enginesis.gameId,
                action: "upload",
                target: uploadAttributes.target,
                file: uploadAttributes.fileName,
                size: uploadAttributes.fileSize,
                token: uploadAttributes.token,
                id: uploadAttributes.uploadId,
                image: fileData
            };
            var fetchOptions = {
                method: "POST",
                mode: "cors",
                credentials: "same-origin",
                cache: 'default',
                headers: formatHTTPHeader(),
                body: convertParamsToFormData(parameters)
            };
            fetch(enginesis.siteResources.assetUploadURL, fetchOptions)
            .then(function (response) {
                var errorCode = '';
                var errorMessage = '';
                if (response && response.ok) {
                    var contentType = response.headers.get("content-type");
                    if (contentType && contentType.includes("application/json")) {
                        response.json().then(function(enginesisResponse) {
                            if (enginesisResponse != null) {
                                if (enginesisResponse.status && enginesisResponse.status.success == "1") {
                                    resolve(enginesisResponse);
                                } else {
                                    errorCode = enginesisResponse.status.message;
                                    errorMessage = enginesisResponse.status.extended_info;
                                }
                            } else {
                                errorCode = "SERVICE_ERROR";
                                errorMessage = "There was a service error during the upload operation. The support team has been notified.";
                            }
                            if (errorCode != "") {
                                reject(makeErrorResponse(errorCode, errorMessage, parameters));
                            }
                        })
                        .catch(function(jsonParseException) {
                            errorCode = "SERVICE_ERROR";
                            errorMessage = jsonParseException.toString();
                            reject(makeErrorResponse(errorCode, errorMessage, parameters));
                        });
                    } else {
                        errorCode = "SERVICE_ERROR";
                        errorMessage = "Unexpected response received from service when requesting upload token.";
                    }
                } else {
                    errorCode = "SERVICE_ERROR";
                    errorMessage = "Error received from service when requesting upload token.";
                }
                if (errorCode != "") {
                    reject(makeErrorResponse(errorCode, errorMessage, parameters));
                }
            }, function (error) {
                errorMessage = "Network error from service when requesting token. " + error.toString();
                reject(makeErrorResponse("SERVICE_ERROR", errorMessage, parameters));
            }).catch(function (exception) {
                errorMessage = "Unexpected response received from service when requesting token with " + exception.toString() + ".";
                reject(makeErrorResponse("SERVICE_ERROR", errorMessage, parameters));
            });
        });
    }

    /**
     * Determine if we have a logged in user.
     * @returns {boolean} True if logged in.
     */
    function isUserLoggedIn() {
        enginesis.isUserLoggedIn = enginesis.loggedInUserInfo && Math.floor(enginesis.loggedInUserInfo.user_id) > 0 && enginesis.authToken != "" && enginesis.authTokenWasValidated;
        // @todo: check not expired, if expired, set to false and ask for sessionRefresh
        return enginesis.isUserLoggedIn;
    }

    /* ============================================================================ *\
     | Public methods: functions below this line are intended to be exposed to
     | external clients.
    \* ============================================================================ */

    var enginesisExport = {

    /**
     * Since this is a singleton object this init function is required before any method can
     * be called. This sets up the initial state of the Enginesis services.
     * @param {object} parameters Configuration object with the following properties:
     *  * `siteId` {integer} required parameter the Enginesis site id.
     *  * `developerKey` {string} required parameter the developer API secret key.
     *  * `gameId` {integer} optional parameter indicates which game id this game represents.
     *  * `gameGroupId` {integer} optional parameter indicates which game group the game belongs to.
     *  * `languageCode` {string} optional parameter to indicate which language the client requests
     *        Enginesis responses.
     *  * `serverStage` {string} which Enginesis server to contact, one of ["", "-d", "-q", "-l", "-x", "*"]. Default
     *        is "*" which indicates to match the stage this client is currently running on.
     *  * `authToken` {string} optional parameter to provide a user authentication token. When not provided
     *        Enginesis will attempt to load it from URL query string (?token=) or cookie.
     *  * `callBackFunction` {function} optional parameter function to call upon a completed request.
     *        See documentation for Enginesis response object structure.
     * @returns {boolean} True if the Enginesis object is considered in a valid operational state and server transactions may proceed, otherwise _false_ and further initialization is required.
     */
    init: function(parameters) {
        let authToken = null;
        enginesisContext = this;
        initializeLocalSessionInfo();
        if (parameters) {
            enginesis.siteId = parameters.siteId !== undefined ? parameters.siteId : 0;
            enginesis.gameId = parameters.gameId !== undefined ? parameters.gameId : 0;
            enginesis.gameKey = parameters.gameKey !== undefined ? parameters.gameKey : "";
            enginesis.gameGroupId = parameters.gameGroupId !== undefined ? parameters.gameGroupId : 0;
            enginesis.languageCode = setLanguageCode(parameters.languageCode);
            enginesis.serverStage = parameters.serverStage !== undefined ? parameters.serverStage : "*";
            enginesis.developerKey = parameters.developerKey !== undefined ? parameters.developerKey : "";
            enginesis.callBackFunction = parameters.callBackFunction !== undefined ? parameters.callBackFunction : null;
            authToken = parameters.authToken !== undefined ? parameters.authToken : null;
        }
        setPlatform();
        setProtocolFromCurrentLocation();
        qualifyAndSetServerStage(enginesis.serverStage);
        restoreUserSession(authToken);
        if (restoreServiceQueue()) {
            // defer the queue processing
            window.setTimeout(restoreOnline, 500);
        }
        return isValidOperationalState();
    },

    /**
     * Compute the MD5 checksum for the given string.
     * @param {string} string String or byte array to compute the checksum.
     * @returns {string} MD5 checksum of the input string.
     */
    md5: function (string) {
        return md5(string);
    },

    /**
     * URL safe version of blowfish encrypt algorithm.
     * Encrypted string is the URL-safe escaped version of base-64: translates +/= to -_~
     * Uses ECB mode only.
     * @param {string} data String of data to encrypt with key.
     * @param {string} key Key must be hex digits represented as string "0123456789abcdef".
     * @return {string} Base-64 encoded data encrypted with key.
     */
    encryptString: function (data, key) {
        return blowfishWrapper.encryptString(data, key);
    },

    /**
     * Decrypt a string that was encrypted with blowfish and the matching key.
     * @param {string} encryptedData String of data that was encrypted with key.
     * @param {string} key Key must be hex digits represented as string "0123456789abcdef".
     * @return {string} Original data.
     */
    decryptString: function(encryptedData, key) {
        return blowfishWrapper.decryptString(encryptedData, key);
    },

    /**
     * Call any service endpoint.
     * @param {string|object} serviceName If string, the Enginesis service name. If object, expects service name to be in the "fn" property of the object.
     * @param {object|null} parameters Key/value parameters to send with request.
     * @returns {Promise} Promise that will resolve when the server replies.
     */
    request: function(serviceName, parameters) {
        if (typeof serviceName === "object" && typeof serviceName.fn === "string") {
            parameters = serviceName;
            serviceName = parameters.fn;
        }
        return sendRequest(serviceName, parameters, null);
    },

    /**
     * Return the Enginesis version.
     * @returns {string} Version.
     */
    versionGet: function () {
        return enginesis.VERSION;
    },

    /**
     * Determine if we have a logged in user.
     * @returns {boolean} True if logged in.
     */
    isUserLoggedIn: function () {
        return isUserLoggedIn();
    },

    /**
     * Return the current logged in user id.
     * @returns {integer} current logged in user id or 0 if no user is logged in.
     */
    userIdGet: function() {
        return enginesis.isUserLoggedIn ? Math.floor(enginesis.loggedInUserInfo.user_id) : 0;
    },

    /**
     * Determine if the object is an Enginesis result object.
     * @param {object} enginesisResult The object to test.
     * @returns {boolean} true if the result is considered an Enginesis result object, otherwise false.
     */
    isEnginesisResult: function (enginesisResult) {
        return enginesisResult && enginesisResult.hasOwnProperty("results") && enginesisResult.results.hasOwnProperty("status") && enginesisResult.results.status.hasOwnProperty("success");
    },

    /**
     * Return the response of the most recent service call.
     * @returns {object} EnginesisResult object.
     */
    getLastResponse: function () {
        if (enginesis.lastResponse) {
            return enginesis.lastResponse.status;
        } else {
            return makeErrorResponse("", "", {});
        }
    },

    /**
     * Determine if the enginesis result is an error.
     * @param {object} enginesisResult
     * @returns {boolean} true if the result is considered an error, false if it succeeded.
     */
    isError: function(enginesisResult) {
        return ! resultIsSuccess(enginesisResult);
    },

    /**
     * Return the error code of a response as a JavaScript error.
     * @param {object} enginesisResult
     * @returns {Error} an error object with code set.
     */
    toError: function(enginesisResult) {
        var error = null;
        var enginesisStatus = null;
        var errorMessage = "";
        if (enginesisResult) {
            if (enginesisResult.status) {
                enginesisStatus = enginesisResult.status;
            } else if (enginesisResult.results && enginesisResult.results.status) {
                enginesisStatus = enginesisResult.results.status;
            }
        }
        if (enginesisStatus == null) {
            enginesisStatus = {
                message: "INVALID_PARAMETER",
                extended_info: "Result was not a valid result object."
            };
        }
        if (enginesisStatus.extended_info) {
            errorMessage = enginesisStatus.extended_info;
        } else {
            errorMessage = enginesisStatus.message;
        }
        error = new Error(errorMessage);
        error.code = enginesisStatus.message;
        return error;
    },

    /**
     * Return the error code of a response as a string.
     * @param {object} enginesisResult
     * @returns {string} The error object reduced to a string of text.
     */
    toErrorString: function (enginesisResult) {
        var errorMessage = "";
        if (enginesisResult && enginesisResult.results && enginesisResult.results.status) {
            errorMessage += enginesisResult.results.status.message;
            if (enginesisResult.results.status.extended_info) {
                errorMessage += ": " + enginesisResult.results.status.extended_info;
            }
        }
        return errorMessage;
    },

    /**
     * Return the error code of a response.
     * @param {object} enginesisResult
     * @returns {string} An Enginesis error code.
     */
    error: function(enginesisResult) {
        return resultErrorCode(enginesisResult);
    },

    /**
     * Generate an enginesis error that looks the same as an error response from the server.
     * This may be helpful to applications with error event handling to consolidate the code
     * so it looks the same as real error responses.
     *
     * @param {string} serviceName The official Enginesis service endpoint that was invoked.
     * @param {integer} stateSeq Session serial number.
     * @param {string} errorCode An Enginesis error code.
     * @param {string} errorMessage Additional info about the error, such as data conditions.
     * @param {object} passThrough Object of parameters supplied to the service endpoint.
     * @returns {object} the Enginesis error object.
     */
    makeErrorResponse: function (serviceName, stateSeq, errorCode, errorMessage, passThrough) {
        return forceErrorResponseObject(serviceName, stateSeq, errorCode, errorMessage, passThrough);
    },

    /**
     * Make a printable string from an enginesis result object. If it is an error, then
     * return a printable error message. If not an error, return a printable summary of
     * the request.
     * @param {object} enginesisResult must be an enginesis result object.
     * @returns {string} the result object interpreted as a printable string.
     */
    resultToString: function(enginesisResult) {
        if (resultIsSuccess(enginesisResult)) {
            return enginesisResult.results.passthru.fn;
        } else {
            return enginesisResult.results.status.message + (enginesisResult.results.status.extended_info ? ": " + enginesisResult.results.status.extended_info : "");
        }
    },

    /**
     * A game must call `pause` when going to background or pausing the game. This allows Enginesis to
     * update its internal state and pause any timers or network requests and wait for the `resume` call.
     */
    pause: function() {
        enginesis.isPaused = true;
    },

    /**
     * A game must call `resume` when restoring from a paused state. Enginesis will undo anything
     * put on hold for pause as well as perform necessary state checks.
     * - Check the session is still valid and not expired and if so issue a refresh in the background.
     */
    resume: function() {
        enginesis.isPaused = false;
        if (enginesis.isUserLoggedIn) {
            // check expiration of the session and refresh if we need to
            enginesis.sessionRefreshIfExpired()
            .then(function(isRefreshed) {
                debugLog("Session was " + (isRefreshed ? "refreshed" : "OK"));
            })
            .catch(function(exception) {
                debugLog("Session sessionRefreshIfExpired exception " + exception.toString());
            });
        }
    },

    /**
     * Return an object of user information. If no user is logged in a valid object is still returned
     * but with invalid user info. Note we do not hand out `loggedInUserInfo` because there are
     * certain properties we do not want clients to access or change.
     * @returns {object} User info.
     */
    getLoggedInUserInfo: function () {
        if (enginesis.isUserLoggedIn) {
            var userInfo = {};
            for (var property in enginesis.loggedInUserInfo) {
                if (enginesis.loggedInUserInfo.hasOwnProperty(property)) {
                    userInfo[property] = enginesis.loggedInUserInfo[property];
                }
            }
            return userInfo;
        } else {
            return null;
        }
    },

    /**
     * Return true if the current device is a touch device.
     * @returns {boolean}
     */
    isTouchDevice: function () {
        return enginesis.isTouchDeviceFlag;
    },

    queryStringToObject: queryStringToObject,

    /**
     * When running as a server in the Node.js environment there is no `fetch` function. This allows
     * the app to set a function to use internally to perform HTTP requests.
     * @param {function} nodeRequest A `fetch` function to use the in Node.js environment.
     */
    setNodeRequest: function(nodeRequest) {
        enginesis.nodeRequest = nodeRequest;
    },

    /**
     * Determine if the user name is a valid format that would be accepted by the server. Since user names are typically
     * provided by users, this helps determine if a proposed user name would be rejected by the server before sending it.
     * @param {string} userName User name to check.
     * @returns {boolean} True if considered valid.
     */
    isValidUserName: function (userName) {
        let isValid;
        if (typeof userName !== "string") {
            isValid = false;
        } else {
            isValid = (userName.length == userName.trim().length) && userName.match(/^[a-zA-Z0-9_@!~\$\.\-\|\s]{3,20}$/) !== null;
        }
        return isValid;
    },

    /**
     * Clean any invalid characters from a proposed user name.
     * @param {string} userName Proposed user name.
     * @returns {string} The userName but cleaned of any invalid characters.
     */
    cleanUserName: function(userName) {
        let cleanName = "";
        if (typeof userName === "string") {
            cleanName = userName.replace(/[^a-zA-Z0-9_@!~\$\.\-\|\s]/g, '');
            cleanName = cleanName.trim().replace(/\s+/, ' ');
        }
        return cleanName;
    },
    
    /**
     * Determine if the password is a valid password that will be accepted by the server. Password
     * must be printable characters with no leading or trailing spaces no less than 8 and no longer
     * than 32.
     * @param {string} password Password to check.
     * @returns {boolean} True if considered valid.
     */
    isValidPassword: function (password) {
        let isValid;
        if (typeof password !== "string") {
            isValid = false;
        } else {
            isValid = (password.length == password.trim().length) && password.match(/^[ -~]{8,32}$/) !== null;
        }
        return isValid;
    },

    /**
     * Determine if the proposed gender is a value we accept and convert it into the value we accept:
     *   "m" or anything that beings with "m|M" is considered "male" and will return "M".
     *   "f" or anything that beings with "f|F" is considered "female" and will return "F".
     *   Anything else is considered unknown and will return "N" (neutral/none/neither).
     * @param {string} gender Proposed gender value.
     * @returns {string} one of "M", "F", "N".
     */
    validateGender: function(gender) {
        return validGender(gender);
    },

    /**
     * Save the user log in refresh token when it is brought in from a server-side
     * log in process and we need to save it client-side.
     */
    saveRefreshToken: function (refreshToken) {
        _saveRefreshToken(refreshToken);
    },

    /**
     * Return the Enginesis refresh token if one has been previously saved.
     * @returns {string}
     */
    getRefreshToken: function () {
        return _getRefreshToken();
    },

    /**
     * Determine and set the server stage from the specified string. It can be a stage request or a domain.
     * @param {string} newServerStage
     * @returns {string}
     */
    serverStageSet: function (newServerStage) {
        return qualifyAndSetServerStage(newServerStage);
    },

    /**
     * Return the current server stage we are set to converse with.
     * @returns {string}
     */
    serverStageGet: function () {
        return enginesis.serverStage;
    },

    /**
     * Get and/or set the use HTTPS flag, allowing the caller to force the protocol. By default we set
     * useHTTPS from the current document location. This allows the caller to query it and override its value.
     * @param {boolean} useHTTPSFlag should be either true to force https or false to force http, or undefined to leave it as is
     * @returns {boolean} the current state of the useHTTPS flag.
     */
    setHTTPS: function (useHTTPSFlag) {
        if (typeof useHTTPSFlag !== "undefined") {
            enginesis.useHTTPS = coerceBoolean(useHTTPSFlag);
        } else {
            enginesis.useHTTPS = true;
        }
        return enginesis.useHTTPS;
    },

    /**
     * Determine if using HTTPS.
     * @returns {boolean} True if using HTTPS.
     */
    isHTTPS: function() {
        return enginesis.useHTTPS;
    },

    /**
     * Get the current HTTP protocol.
     * @returns {string} HTTP protocol, either "http" or "https".
     */
    getProtocol: function() {
        return getProtocol();
    },

    /**
     * Return the base URL we are using to converse with the server.  We can use this base URL to construct a path to
     * sub-services.
     * @returns {string}
     */
    serverBaseUrlGet: function () {
        return enginesis.serverHost;
    },

    /**
     * Each site registers a set of resources apps may need to do certain things that are site-specific.
     * These host names are also configured to the current stage and protocol. This set of URLs/resources
     * is configured on the server for each site and the server should be queried the first time to get
     * them. They rarely change so caching should be fine. This function returns
     * an object populated with the following urls:
     *  * `root` = the root of the website
     *  * `profile` = the page that holds the user's profile page when they are logged in
     *  * `register` = the page users go to register new accounts
     *  * `forgotPassword` = the page users go to reset their password
     *  * `login` = the page users go to log in
     *  * `privacy` = the page holding the privacy policy
     *  * `terms` = the page holding the terms of use/service policy
     *  * `play` = the page where to play a game
     * @returns {object} object holding the set of server URLs.
     */
    getSiteSpecificURLs: function() {
        var urlBase;
        var siteResources = enginesis.siteResources;

        if (siteResources.profileURL != undefined && siteResources.profileURL.length > 0) {
            urlBase = getProtocol() + siteResources.baseURL;
            return {
                root: urlBase,
                forgotPassword: urlBase + siteResources.forgotPasswordURL,
                login: urlBase + siteResources.loginURL,
                play: urlBase + siteResources.playURL,
                privacy: urlBase + siteResources.privacyURL,
                profile: urlBase + siteResources.profileURL,
                register: urlBase + siteResources.registerURL,
                terms: urlBase + siteResources.termsURL
            };
        } else {
            // @todo: if SessionBegin was not called we won't have this information. We need an alternative in this scenario. Maybe force a call to SessionBegin?
            // @todo: using varyn.com as the default makes no sense here.
            urlBase = getProtocol() + "varyn" + enginesis.serverStage + ".com";
            return {
                root: urlBase,
                forgotPassword: urlBase + "/procs/forgotpass.php",
                login: urlBase + "/profile/",
                play: urlBase + "/play/",
                privacy: urlBase + "/privacy/",
                profile: urlBase + "/profile/",
                register: urlBase + "/profile/?action=signup",
                terms: urlBase + "/tos/"
            };
        }
    },

    /**
     * Return the current game-id that's been previously set with `gameIdSet`, `init`, or
     * `SessionBegin`.
     * @returns {number}
     */
    gameIdGet: function () {
        return enginesis.gameId;
    },

    /**
     * Set or override the current game-id.
     * @param {integer} newGameId An Enginesis game identifier.
     * @param {string} newGameKey The Enginesis game key associated with the game id.
     * @returns {boolean} True if set.
     */
    gameIdSet: function (newGameId, newGameKey) {
        if (enginesis.gameId != newGameId) {
            enginesis.gameInfo = null;
        }
        enginesis.gameKey = newGameKey || "";
        return enginesis.gameId = newGameId;
    },

    /**
     * Get the game properties of the current game-id if one was set.
     * @returns {object|null} Key value object of the game properties, or null if not set.
     */
    gameInfoGet: function () {
        return enginesis.gameInfo;
    },

    /**
     * Return the current game-group-id.
     * @returns {integer}
     */
    gameGroupIdGet: function () {
        return enginesis.gameGroupId;
    },

    /**
     * Set or override the current game-group-id.
     * @param {integer} newGameGroupId
     * @returns {integer}
     */
    gameGroupIdSet: function (newGameGroupId) {
        return enginesis.gameGroupId = newGameGroupId;
    },

    /**
     * Return the current site-id.
     * @returns {integer}
     */
    siteIdGet: function () {
        return enginesis.siteId;
    },

    /**
     * Return the list of supported networks capable of SSO.
     * @returns {enginesis.supportedNetworks|{Enginesis, Facebook, Google, Twitter, Apple}}
     */
    supportedSSONetworks: function() {
        return enginesis.supportedNetworks;
    },

    /**
     * Return the URL of the request game image.
     * @param {object} parameters Parameters object as we want to be flexible about what we will accept.
     * Parameters are:
     * * `gameName` {string} game folder on server where the game assets are stored. Most of the game queries
     *    (GameGet, GameList, etc) return game_name and this is used as the game folder.
     * * `width` {integer} optional width, use null to ignore. Server will choose common width.
     * * `height` {integer} optional height, use null to ignore. Server will choose common height.
     * * `format` {string} optional image format, use null and server will choose. Otherwise {jpg|png|svg}
     * @returns {string} a URL you can use to load the image.
     * @todo: this really needs to call a server-side service to perform this resolution as we need to use PHP to determine which files are available and the closest match.
     */
    getGameImageURL: function (parameters) {
        let gameName = null;
        let width = 0;
        let height = 0;
        let format = null;
        let defaultImageFormat = ".png";

        if (typeof parameters !== "undefined" && parameters != null) {
            if ( ! isEmpty(parameters.game_name)) {
                gameName = parameters.game_name;
            } else if ( ! isEmpty(parameters.gameName)) {
                gameName = parameters.gameName;
            }
            if ( ! isEmpty(parameters.format)) {
                format = parameters.format;
            }
            if (typeof parameters.width !== "undefined") {
                width = parameters.width;
            }
            if (typeof parameters.height !== "undefined") {
                height = parameters.height;
            }
        }
        if (isEmpty(format)) {
            format = defaultImageFormat;
        } else {
            if (format[0] != ".") {
                format = "." + format;
            }
            if ( ! format.match(/\.(jpg|png|svg)/i)) {
                format = defaultImageFormat;
            }
        }
        if (isEmpty(gameName) && enginesis.gameInfo != null) {
            gameName = enginesis.gameInfo.game_name || "enginesisTestGame";
        }
        if (gameName == "quiz") {
            gameName += "/" + (parameters.game_id || enginesis.gameInfo.game_id);
        }
        if (isEmpty(width) || width == "*") {
            width = 600;
        }
        if (isEmpty(height) || height == "*") {
            height = 450;
        }
        return getProtocol() + enginesis.serverHost + "/games/" + gameName + "/images/" + width + "x" + height + format;
    },

    /**
     * Return the current UTC date in a standard format such as "2017-01-15 23:11:52".
     * @returns {string}
     */
    getDateNow: function () {
        return new Date().toISOString().slice(0, 19).replace("T", " ");
    },

    /**
     * If an external source determines the network has been restored, call this method to tell Enginesis
     * we are back online and continue server communications. If the client app does not call this it could
     * take a while before Enginesis figures out it is back online again.
     * @returns {Promise} This method returns a promise that should resolve once any pending service calls
     *   are complete.
     */
    restoreOnline: function() {
        return restoreOnline();
    },

    /**
     * Call Enginesis SessionBegin which is used to start any conversation with the server. Must call before beginning a game.
     * @param {string} gameKey service provided game key matching gameId
     * @param {integer|null} gameId The game id. If null/0 then assumes the gameId was set in the constructor or with gameIdSet()
     * @param {function} overRideCallBackFunction Function called when server replies.
     * @returns {Promise} Resolved with enginesisResult when the server replies.
     */
    sessionBegin: function (gameKey, gameId, overRideCallBackFunction) {
        const serviceName = "SessionBegin";
        let siteMark = 0;
        if ( ! enginesis.isUserLoggedIn) {
            cookieSet(enginesis.anonymousUserKey, enginesis.anonymousUser, 60 * 60 * 24, "/", "", true);
            siteMark = enginesis.anonymousUser.userId;
        }
        var parameters = {
            game_id: isEmpty(gameId) ? enginesis.gameIdGet() : gameId,
            gamekey: isEmpty(gameKey) ? enginesis.gameKey : gameKey,
            site_mark: siteMark
        };
        return sendRequest(serviceName, parameters, overRideCallBackFunction);
    },

    /**
     * Call Enginesis SessionRefresh to exchange the long-lived refresh token for a new authentication token. Usually you
     * call this when you attempt to call a service and it replied with TOKEN_EXPIRED.
     * @param {string} refreshToken optional, if not provided (empty/null) then we try to pull the one we have in the local store.
     * @param {function} overRideCallBackFunction
     * @returns {Promise} Resolves if successful but if fails then call `getLastResponse` to get an error code as to what went wrong.
     */
    sessionRefresh: function (refreshToken, overRideCallBackFunction) {
        const serviceName = "SessionRefresh";
        if (isEmpty(refreshToken)) {
            refreshToken = _getRefreshToken();
            if (isEmpty(refreshToken)) {
                return immediateErrorResponse(serviceName, {}, "NOT_LOGGED_IN", "The requested service requires a logged in user.", overRideCallBackFunction);
            }
        }
        let siteMark = 0;
        if ( ! enginesis.isUserLoggedIn) {
            siteMark = enginesis.anonymousUser.userId;
        }
        const parameters = {
            refresh_token: refreshToken,
            game_id: enginesis.gameId,
            gamekey: enginesis.gameKey,
            site_mark: siteMark
        };
        return sendRequest(serviceName, parameters, overRideCallBackFunction);
    },

    /**
     * Proactive check to see if the user's session has expired, and if so, refresh it.
     * @returns {Promise} A Promise that will resolve if the session is OK or if the session has been refreshed.
     *   If this rejects, it usually means a session doesn't exist, the user is not truly logged in, or the
     *   session information we have in the cache has been compromised.
     */
    sessionRefreshIfExpired: function () {
        return verifyUserSessionInfo();
    },

    /**
     * Submit a vote for a URI key.
     * @param {string} voteURI The URI key of the item we are voting on.
     * @param {string} voteGroupURI The URI group used to sub-group keys, for example you are voting on the best of 5 images.
     * @param {integer} voteValue The value of the vote. This depends on the voting system set by the URI key/group (for example a rating vote may range from 1 to 5.)
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    voteForURIUnauth: function (voteURI, voteGroupURI, voteValue, securityKey, overRideCallBackFunction) {
        return sendRequest("VoteForURIUnauth", {uri: voteURI, vote_group_uri: voteGroupURI, vote_value: voteValue, security_key: securityKey}, overRideCallBackFunction);
    },

    /**
     * Return voting results by voting group key.
     * @param {string} voteGroupURI voting group that collects all the items to be voted on
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     * @see: addOrUpdateVoteByURI
     */
    voteCountPerURIGroup: function (voteGroupURI, overRideCallBackFunction) {
        return sendRequest("VoteCountPerURIGroup", {vote_group_uri: voteGroupURI}, overRideCallBackFunction);
    },

    /**
     * Return information about a specific Enginesis Developer.
     * @param {integer} developerId Developer id.
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    developerGet: function (developerId, overRideCallBackFunction) {
        return sendRequest("DeveloperGet", {developer_id: developerId}, overRideCallBackFunction);
    },

    /**
     * Get user generated game data. Not to be confused with gameConfigGet (which is system generated.)
     * @param {integer} gameDataId The specific id assigned to the game data to get. Was generated by gameDataCreate.
     * @returns {Promise}
     */
    gameDataGet: function (gameDataId, overRideCallBackFunction) {
        return sendRequest("GameDataGet", {game_data_id: gameDataId}, overRideCallBackFunction);
    },

    /**
     * Create a user generated content object on the server and send it to the requested individual.
     * @param referrer
     * @param fromAddress
     * @param fromName
     * @param toAddress
     * @param toName
     * @param userMessage
     * @param userFiles
     * @param gameData
     * @param nameTag
     * @param addToGallery
     * @param lastScore
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    gameDataCreate: function (referrer, fromAddress, fromName, toAddress, toName, userMessage, userFiles, gameData, nameTag, addToGallery, lastScore, overRideCallBackFunction) {
        return sendRequest("GameDataCreate", {
            referrer: referrer,
            from_address: fromAddress,
            from_name: fromName,
            to_address: toAddress,
            to_name: toName,
            user_msg: userMessage,
            user_files: userFiles,
            game_data: gameData,
            name_tag: nameTag,
            add_to_gallery: addToGallery ? 1 : 0,
            last_score: lastScore
        }, overRideCallBackFunction);
    },

    /**
     * Send to Friend is the classic share a game service. It uses the GameDataCreate service but
     * optimized to sharing a game or a user's completed game that she wants to share with a friend.
     * @param {object} sendAttributes Required and optional parameters to send.
     *   * `referrer`: Optional, string to indicate the origin of the request. Usually provide the game_name here but it isn't necessary.
     *   * `from_address`: Email address of the sender. Optional is user is logged in, then will use registered user's email. Required if user is not logged in and unauthenticated send is allowed.
     *   * `from_name`: Optional, string indicating sender user's name, used only when from_address is used.
     *   * `to_address`: Required, email address to send to.
     *   * `to_name`: Required, name of the recipient.
     *   * `user_message`: Optional, string of additional user message to include in the email.
     *   * `game_data`: Optional, additional game data to be provided in the message that could be passed into the game when the recipient goes to play it.
     *   * `name_tag`: Optional, string, additional search tags to assign to the game data.
     *   * `add_to_gallery`: Optional, boolean, 1 to include this in a gallery, 0 to not include in the game gallery.
     *   * `last_score`: Optional, a game score to provide with the game data and report in the user email.
     *   * `game_image`: Optional, blob, an image to include in the email message.
     * @param {function} overRideCallBackFunction
     * @returns {Promise} Resolves when the server request completes.
     */
    sendToFriend: function(sendAttributes, overRideCallBackFunction) {
        var errorCode = "";
        var service = "GameDataCreate";
        if (( ! enginesis.authTokenWasValidated || Math.floor(enginesis.loggedInUserInfo.user_id) == 0) && (isEmpty(sendAttributes.from_address) || isEmpty(sendAttributes.from_name))) {
            // if not logged in, fromAddress, fromName must be provided. Otherwise we get it on the server from the logged in user info.
            errorCode = "INVALID_PARAMETER";
        } else if (isEmpty(enginesis.gameId)) {
            errorCode = "INVALID_GAME_ID";
        }
        if (errorCode == "") {
            var requestParameters = {
                referrer: sendAttributes.referrer || "enginesis",
                from_address: sendAttributes.from_address || "",
                from_name: sendAttributes.from_name || "",
                to_address: sendAttributes.to_address || "",
                to_name: sendAttributes.to_name || "User",
                user_msg: sendAttributes.user_message || "",
                user_files: "",
                game_data: sendAttributes.game_data || "",
                name_tag: sendAttributes.name_tag || "",
                add_to_gallery: sendAttributes.add_to_gallery || 0,
                last_score: sendAttributes.last_score || 0
            };
            // If a game image is present, get it on the server first and get it's file ref before sending the complete request.
            if (sendAttributes.game_image) {
                return new Promise(function(resolve) {
                    _requestFileUpload("gameshare", "game_image.png", sendAttributes.game_image)
                    .then(function(enginesisResponse) {
                        if (enginesisResponse.status && enginesisResponse.status.success == "1") {
                            requestParameters.user_files = enginesisResponse.results.path + enginesisResponse.results.file;
                        }
                        sendRequest(service, requestParameters, overRideCallBackFunction)
                        .then(function(enginesisResponse) {
                            resolve(enginesisResponse);
                        });
                    }, function(enginesisResponse) {
                        // there was an error uploading the file, should deal with it, but OK to continue
                        debugLog("SendToFriend error " + enginesisResponse.toString() + " while uploading image, continuing anyway.");
                        sendRequest(service, requestParameters, overRideCallBackFunction)
                        .then(function(enginesisResponse) {
                            resolve(enginesisResponse);
                        });
                    })
                    .catch(function(exception) {
                        // there was an error uploading the file, should deal with it, but OK to continue
                        debugLog("SendToFriend exception " + exception.toString() + " while uploading image, continuing anyway.");
                        sendRequest(service, requestParameters, overRideCallBackFunction)
                        .then(function(enginesisResponse) {
                            resolve(enginesisResponse);
                        });
                    });
                    // callbackPriority(enginesisResult, resolve, overRideCallBackFunction, enginesis.callBackFunction);
                });
            } else {
                return sendRequest(service, requestParameters, overRideCallBackFunction);
            }
        } else {
            return immediateErrorResponse(service, {game_id: enginesis.gameId, from_address: sendAttributes.from_address, from_name: sendAttributes.from_name}, errorCode, "Error " + errorCode + " encountered while processing send to friend.", overRideCallBackFunction);
        }
    },

    /**
     * Get game data configuration. Not to be confused with GameData (which is user generated.)
     * @param {integer} gameConfigId A specific game data configuration to get. If provided the other parameters are ignored.
     * @param {integer} gameId The gameId, if 0 then the gameId set previously will be assumed. gameId is mandatory.
     * @param {integer} categoryId A category id if the game organizes its data configurations by categories. Otherwise use 0.
     * @param {date} airDate A specific date to return game configuration data. Use "" to let the server decide (usually means "today" or most recent.)
     * @returns {Promise}
     */
    gameConfigGet: function (gameConfigId, gameId, categoryId, airDate, overRideCallBackFunction) {
        if (typeof gameConfigId === "undefined") {
            gameConfigId = 0;
        }
        if (typeof gameId === "undefined" || gameId == 0) {
            gameId = enginesis.gameIdGet();
        }
        if (typeof airDate === "undefined") {
            airDate = "";
        }
        if (typeof categoryId === "undefined") {
            categoryId = 0;
        }
        return sendRequest("GameConfigGet", {game_config_id: gameConfigId, game_id: gameId, category_id: categoryId, air_date: airDate}, overRideCallBackFunction);
    },

    /**
     * Track a game event for game-play metrics.
     * @param {string} category what event generated the request (load, start, showAd, etc.)
     * @param {string} action further qualifying data about the event (depends on the event.)
     * @param {string} label path in game where event occurred
     * @param {string} hitData a value related to the action, quantifying the action, if any
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    gameTrackingRecord: function (category, action, label, hitData, overRideCallBackFunction) {
        if (enginesis.isBrowserBuild) {
            try {
                if (isNull(action)) {
                    action = this.gameIdGet().toString();
                }
                if (isNull(label)) {
                    label = "";
                }
                if (isNull(hitData)) {
                    hitData = "";
                }
                // use Google Analytics or Tag Manager if it is there (send event, category, action, label, value)
                if (window.dataLayer != undefined) {
                    window.dataLayer.push({"event": category, "gameid": this.gameIdGet().toString(), "action": action, "label": label, "value": hitData});
                } else if (window.ga != undefined) {
                    window.ga("send", "event", category, action, label, hitData);
                }
            } catch (exception) {
                debugLog("Analytics exception " + exception.toString());
            }
        }
        return sendRequest("GameTrackingRecord", {hit_type: "game_event", hit_category: category, hit_action: action, hit_label: label, hit_data: hitData}, overRideCallBackFunction);
    },

    /**
     * Search for games given a keyword search.
     * @param {string} game_name_part
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    gameFind: function(game_name_part, overRideCallBackFunction) {
        return sendRequest("GameFind", {game_name_part: game_name_part}, overRideCallBackFunction);
    },

    /**
     * Search for games by only searching game names.
     * @param {string} gameName Game name or part of game name to search for.
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    gameFindByName: function (gameName, overRideCallBackFunction) {
        return sendRequest("GameFindByName", {game_name: gameName}, overRideCallBackFunction);
    },

    /**
     * Return game info given a specific game-id.
     * @param {integer} gameId Id of game to get.
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    gameGet: function (gameId, overRideCallBackFunction) {
        return sendRequest("GameGet", {game_id: gameId}, overRideCallBackFunction);
    },

    /**
     * Return game info given the game name.
     * @param {string} gameName
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    gameGetByName: function (gameName, overRideCallBackFunction) {
        return sendRequest("GameGetByName", {game_name: gameName}, overRideCallBackFunction);
    },

    /**
     * Return a list of games for each game category.
     * @param {integer} numItemsPerCategory
     * @param {integer} gameStatusId
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    gameListByCategory: function (numItemsPerCategory, gameStatusId, overRideCallBackFunction) {
        return sendRequest("GameListByCategory", {num_items_per_category: numItemsPerCategory, game_status_id: gameStatusId}, overRideCallBackFunction);
    },

    /**
     * Return a list of available game lists for the current site-id.
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    gameListList: function (overRideCallBackFunction) {
        return sendRequest("GameListList", {}, overRideCallBackFunction);
    },

    /**
     * Return the list of games belonging to the requested game list id.
     * @param {integer} gameListId
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    gameListListGames: function (gameListId, overRideCallBackFunction) {
        return sendRequest("GameListListGames", {game_list_id: gameListId}, overRideCallBackFunction);
    },

    /**
     * Return the list of games belonging to the requested game list given its name.
     * @param {string} gameListName
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    gameListListGamesByName: function (gameListName, overRideCallBackFunction) {
        return sendRequest("GameListListGamesByName", {game_list_name: gameListName}, overRideCallBackFunction);
    },

    gameListByMostPopular: function (startDate, endDate, startItem, numberOfItems, overRideCallBackFunction) {
        return sendRequest("GameListByMostPopular", {start_date: startDate, end_date: endDate, start_item: startItem, num_items: numberOfItems}, overRideCallBackFunction);
    },

    /**
     * Return a list of games when given a list of individual game ids. Specify the list delimiter, default is ','.
     * @param {integer} gameIdList
     * @param {string} delimiter
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    gameListByIdList: function (gameIdList, delimiter, overRideCallBackFunction) {
        return sendRequest("GameListByIdList", {game_id_list: gameIdList, delimiter: delimiter}, overRideCallBackFunction);
    },

    gameListCategoryList: function (overRideCallBackFunction) {
        return sendRequest("GameListCategoryList", {}, overRideCallBackFunction);
    },

    gameListListRecommendedGames: function (gameListId, overRideCallBackFunction) {
        return sendRequest("GameListListRecommendedGames", {game_list_id: gameListId}, overRideCallBackFunction);
    },

    gamePlayEventListByMostPlayed: function (startDate, endDate, numberOfItems, overRideCallBackFunction) {
        return sendRequest("GamePlayEventListByMostPlayed", {start_date: startDate, end_date: endDate, num_items: numberOfItems}, overRideCallBackFunction);
    },

    gameRatingGet: function (gameId, overRideCallBackFunction) {
        return sendRequest("GameRatingGet", {game_id: gameId}, overRideCallBackFunction);
    },

    gameRatingList: function (gameId, numberOfGames, overRideCallBackFunction) {
        return sendRequest("GameRatingList", {game_id: gameId, num_items: numberOfGames}, overRideCallBackFunction);
    },

    gameRatingUpdate: function (gameId, rating, overRideCallBackFunction) {
        return sendRequest("GameRatingUpdate", {game_id: gameId, rating: rating}, overRideCallBackFunction);
    },

    scoreSubmitUnauth: function (gameId, userName, score, gameData, timePlayed, userSource, overRideCallBackFunction) {
        var sessionId = enginesis.sessionId;
        // @todo: userName = enginesis.anonymousUser.userName, site_mark = enginesis.anonymousUser.userId;
        return sendRequest("ScoreSubmitUnauth", {game_id: gameId, session_id: sessionId, user_name: userName, score: score, game_data: gameData, time_played: timePlayed, user_source: userSource}, overRideCallBackFunction);
    },

    // ScoreSubmitRankGetUnauth
    // ScoreSubmitRankListUnauth
    // ScoreSubmitForHold

    /**
     * Submit a final game score to the server. This requires a logged in user and a prior
     * call to SessionBegin to establish a game session with the server.
     * @param {integer|null} gameId if 0/null provided we use the gameId set on the Enginesis object. A
     *    game id is mandatory for sumitting a score.
     * @param {integer} score a value within the range established for the game.
     * @param {string} gameData option data regarding the game play. This is data specific to the
     *    game but should be in a consistent format for all submissions of that game.
     * @param {integer} timePlayed the number of milliseconds the game was played for the game play
     *    session that produced the score (i.e. don't include canceled games, restarts, total time
     *    the app was open, etc.)
     * @param {function} overRideCallBackFunction once the server responds resolve to this function.
     *    If not provided then resolves to the global callback function, if set.
     * @returns {Promise} once the server responds resolve to this function.
     */
    scoreSubmit: function (gameId, score, gameData, timePlayed, overRideCallBackFunction) {
        var service = "ScoreSubmit";
        var sessionId = enginesis.sessionId;
        var submitString;
        var errorCode = "";

        // verify user is logged in, cannot submit a score if no one is logged in. A logged in user
        // should also have a valid session (SessionBegin must have been called). And of course a
        // game-id is required.
        if ( ! enginesis.authTokenWasValidated || Math.floor(enginesis.loggedInUserInfo.user_id) == 0) {
            errorCode = "NOT_LOGGED_IN";
        } else if (sessionId == null) {
            errorCode = "INVALID_SESSION";
        } else {
            if (isEmpty(gameId)) {
                gameId = enginesis.gameId;
                if (isEmpty(gameId)) {
                    errorCode = "INVALID_GAME_ID";
                }
            }
        }
        if (errorCode == "") {
            submitString = encryptScoreSubmit(enginesis.siteId, enginesis.loggedInUserInfo.user_id, gameId, score, gameData, timePlayed, sessionId);
            if (submitString == null) {
                errorCode = "INVALID_PARAMETER";
            }
        }
        if (errorCode == "") {
            return sendRequest(service, {data: submitString}, overRideCallBackFunction);
        } else {
            return immediateErrorResponse(service, {game_id: gameId, score: score, game_data: gameData, time_played: timePlayed}, errorCode, "Error encountered while processing score submit.", overRideCallBackFunction);
        }
    },

    // ScoreSubmitRankGet
    // ScoreSubmitRankList

    scoreRankList: function(gameId, timePeriodType, timePeriod, startRank, numberOfRanks, overRideCallBackFunction) {
        var service = "ScoreRankList";
        var errorCode = "";
        if (isEmpty(gameId)) {
            gameId = enginesis.gameId;
            if (isEmpty(gameId)) {
                errorCode = "INVALID_GAME_ID";
            }
        }
        if (isEmpty(timePeriodType)) {
            timePeriodType = 0;
        }
        if (isEmpty(timePeriod)) {
            timePeriod = 0;
        }
        if (isEmpty(startRank)) {
            startRank = 1;
        }
        if (isEmpty(numberOfRanks)) {
            numberOfRanks = 100;
        }
        var parameters = {
            game_id: gameId,
            time_period_type: timePeriodType,
            time_period: timePeriod,
            start_rank: startRank,
            num_ranks: numberOfRanks
        };
        return sendRequest(service, parameters, overRideCallBackFunction);
    },

    newsletterCategoryList: function (overRideCallBackFunction) {
        return sendRequest("NewsletterCategoryList", {}, overRideCallBackFunction);
    },

    newsletterAddressAssign: function (emailAddress, userName, companyName, categories, overRideCallBackFunction) {
        return sendRequest("NewsletterAddressAssign", {email_address: emailAddress, user_name: userName, company_name: companyName, categories: categories, delimiter: ","}, overRideCallBackFunction);
    },

    newsletterAddressUpdate: function (newsletterAddressId, emailAddress, userName, companyName, active, overRideCallBackFunction) {
        return sendRequest("NewsletterAddressUpdate", {newsletter_address_id: newsletterAddressId, email_address: emailAddress, user_name: userName, company_name: companyName, active: active}, overRideCallBackFunction);
    },

    newsletterAddressDelete: function (emailAddress, overRideCallBackFunction) {
        return sendRequest("NewsletterAddressDelete", {email_address: emailAddress, newsletter_address_id: "NULL"}, overRideCallBackFunction);
    },

    newsletterAddressGet: function (emailAddress, overRideCallBackFunction) {
        return sendRequest("NewsletterAddressGet", {email_address: emailAddress}, overRideCallBackFunction);
    },

    promotionItemList: function (promotionId, queryDate, overRideCallBackFunction) {
        // promotionId is required. queryDate can be null or a valid date
        return sendRequest("PromotionItemList", {promotion_id: promotionId, query_date: queryDate}, overRideCallBackFunction);
    },

    promotionList: function (promotionId, queryDate, showItems, overRideCallBackFunction) {
        // promotionId is required. queryDate can be null or a valid date. showItems if true/false, default is false
        return sendRequest("PromotionItemList", {promotion_id: promotionId, query_date: queryDate, show_items: showItems}, overRideCallBackFunction);
    },

    recommendedGameList: function (gameId, overRideCallBackFunction) {
        return sendRequest("RecommendedGameList", {game_id: gameId}, overRideCallBackFunction);
    },

    registeredUserCreate: function (userName, password, email, realName, dateOfBirth, gender, city, state, zipcode, countryCode, mobileNumber, imId, tagline, siteUserId, networkId, agreement, securityQuestionId, securityAnswer, imgUrl, aboutMe, additionalInfo, sourceSiteId, captchaId, captchaResponse, overRideCallBackFunction) {
        return sendRequest("RegisteredUserCreate", {
            site_id: siteId,
            captcha_id: isEmpty(captchaId) ? enginesis.captchaId : captchaId,
            captcha_response: isEmpty(captchaResponse) ? enginesis.captchaResponse : captchaResponse,
            user_name: userName,
            site_user_id: siteUserId,
            network_id: networkId,
            real_name: realName,
            password: password,
            dob: dateOfBirth,
            gender: gender,
            city: city,
            state: state,
            zipcode: zipcode,
            email_address: email,
            country_code: countryCode,
            mobile_number: mobileNumber,
            im_id: imId,
            agreement: agreement,
            security_question_id: 1,
            security_answer: '',
            img_url: '',
            about_me: aboutMe,
            tagline: tagline,
            additional_info: additionalInfo,
            source_site_id: sourceSiteId
        }, overRideCallBackFunction);
    },

    registeredUserUpdate: function (userName, password, email, realName, dateOfBirth, gender, city, state, zipcode, countryCode, mobileNumber, imId, tagline, siteUserId, networkId, agreement, securityQuestionId, securityAnswer, imgUrl, aboutMe, additionalInfo, sourceSiteId, captchaId, captchaResponse, overRideCallBackFunction) {
        return sendRequest("RegisteredUserUpdate", {
            site_id: siteId,
            captcha_id: isEmpty(captchaId) ? enginesis.captchaId : captchaId,
            captcha_response: isEmpty(captchaResponse) ? enginesis.captchaResponse : captchaResponse,
            user_name: userName,
            real_name: realName,
            dob: dateOfBirth,
            gender: gender,
            city: city,
            state: state,
            zipcode: zipcode,
            email_address: email,
            country_code: countryCode,
            mobile_number: mobileNumber,
            im_id: imId,
            img_url: '',
            about_me: aboutMe,
            tagline: tagline,
            additional_info: additionalInfo
        }, overRideCallBackFunction);
    },

    registeredUserSecurityUpdate: function (captchaId, captchaResponse, security_question_id, security_question, security_answer, overRideCallBackFunction) {
        return sendRequest("RegisteredUserSecurityUpdate", {
            site_id: siteId,
            captcha_id: isEmpty(captchaId) ? enginesis.captchaId : captchaId,
            captcha_response: isEmpty(captchaResponse) ? enginesis.captchaResponse : captchaResponse,
            security_question_id: security_question_id,
            security_question: security_question,
            security_answer: security_answer
        }, overRideCallBackFunction);
    },

    /**
     * Confirm a new user registration given the user-id and the token. These are supplied in the email sent when
     * a new registration is created with RegisteredUserCreate. If successful the user is logged in and a login
     * token (authtok) is sent back from the server.
     * @param user_id
     * @param secondary_password
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    registeredUserConfirm: function (user_id, secondary_password, overRideCallBackFunction) {
        return sendRequest("RegisteredUserConfirm", {user_id: user_id, secondary_password: secondary_password}, overRideCallBackFunction);
    },

    /**
     * this function generates the email that is sent to the email address matching username or email address.
     * that email leads to the change password web page. Currently only user name or email address is required to invoke
     * the flow, but we should consider more matching info before we start it in case accounts are being hacked.
     * @param {string} userName
     * @param {string} email
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    registeredUserForgotPassword: function (userName, email, overRideCallBackFunction) {
        return sendRequest("RegisteredUserForgotPassword", {user_name: userName, email: email}, overRideCallBackFunction);
    },

    /**
     * this function generates the email that is sent to the email address matching user_id if the secondary password matches.
     * This is used when the secondary password is attempted but expired (such as user lost the reset email).
     *
     * @param {integer} user_id - the user in question, required if using secondary password flow, optional if using user_name or email_address.
     * @param {string} user_name - the user in question if you do not know the user_id
     * @param {string} email_address - identify the user by email address
     * @param {string} secondary_password - the original secondary password generated in forgot password flow.
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    registeredUserResetSecondaryPassword: function (user_id, user_name, email_address, secondary_password, overRideCallBackFunction) {
        return sendRequest("RegisteredUserResetSecondaryPassword", {
            user_id: user_id,
            user_name: user_name,
            email_address: email_address,
            secondary_password: secondary_password
        }, overRideCallBackFunction);
    },

    registeredUserRequestPasswordChange: function (overRideCallBackFunction) {
        return sendRequest("RegisteredUserRequestPasswordChange", {
            site_id: enginesis.siteId
        }, overRideCallBackFunction);
    },

    // @todo: Should include the user-id?
    registeredUserPasswordChange: function (captchaId, captchaResponse, password, secondary_password, overRideCallBackFunction) {
        return sendRequest("RegisteredUserPasswordChange", {
            site_id: siteId,
            captcha_id: isEmpty(captchaId) ? enginesis.captchaId : captchaId,
            captcha_response: isEmpty(captchaResponse) ? enginesis.captchaResponse : captchaResponse,
            password: password,
            secondary_password: secondary_password
        }, overRideCallBackFunction);
    },

    registeredUserSecurityGet: function (overRideCallBackFunction) {
        return sendRequest("RegisteredUserSecurityGet", {
            site_id: enginesis.siteId,
            site_user_id: ''
        }, overRideCallBackFunction);
    },

    registeredUserGet: function (userId, siteUserId, networkId, overRideCallBackFunction) {
        // Return public information about user given id
        return sendRequest("RegisteredUserGet", {get_user_id: userId, site_user_id: siteUserId, network_id: networkId}, overRideCallBackFunction);
    },

    siteListGames: function(startItem, numberOfItems, gameStatusId, overRideCallBackFunction) {
        // return a list of all assets assigned to the site in title order
        if (startItem == null || startItem < 0) {
            startItem = 1;
        }
        if (numberOfItems == null || numberOfItems > 500) {
            numberOfItems = 500;
        }
        if (gameStatusId == null || gameStatusId > 3) {
            gameStatusId = 2;
        }
        return sendRequest("SiteListGames", {start_item: startItem, num_items: numberOfItems, game_status_id: gameStatusId}, overRideCallBackFunction);
    },

    siteListGamesRandom: function(numberOfItems, overRideCallBackFunction) {
        if (numberOfItems == null || numberOfItems > 500) {
            numberOfItems = 500;
        }
        return sendRequest("SiteListGamesRandom", {num_items: numberOfItems}, overRideCallBackFunction);
    },

    userGetByName: function (userName, overRideCallBackFunction) {
        // Return public information about user give name
        return sendRequest("UserGetByName", {user_name: userName}, overRideCallBackFunction);
    },

    /**
     * Log out the current logged in user. This invalidates any session data we are holding
     * both locally and on the server.
     * @returns {Promise} A promise that resolves with the server's response.
     */
    userLogout: function(overRideCallBackFunction) {
        return sendRequest("UserLogout", {}, overRideCallBackFunction);
    },

    /**
     * Perform a user login given a user name (also accepts user email address) and the password.
     * In the callback function you receive a response if the login succeeds or not. A successful
     * login provides information about the user.
     * @param {string} userName The user name or email to identify the user.
     * @param {string} password The user's password which shold conform to the password rules.
     * @returns {Promise} A promise that resolves with the server's response.
     */
    userLogin: function(userName, password, overRideCallBackFunction) {
        return sendRequest("UserLogin", {user_name: userName, password: password}, overRideCallBackFunction);
    },

    /**
     * Enginesis co-registration accepts validated login from another network and creates a new user or logs in
     * a matching user. site-user-id, user-name, and network-id are mandatory. Everything else is optional.
     * @param registrationParameters {object} registration data values. We accept
     *   siteUserId
     *   userName
     *   realName
     *   emailAddress
     *   agreement
     *   gender
     *   dob
     *   avatarURL
     *   idToken
     *   scope
     * @param {integer} networkId We must know which network this registration comes from.
     * @param {function} overRideCallBackFunction {function} called when server replies.
     */
    userLoginCoreg: function (registrationParameters, networkId, overRideCallBackFunction) {
        if (typeof registrationParameters.siteUserId === "undefined" || registrationParameters.siteUserId.length == 0) {
            return false;
        }
        if ((typeof registrationParameters.userName === "undefined" || registrationParameters.userName.length == 0) && (typeof registrationParameters.realName === "undefined" || registrationParameters.realName.length == 0)) {
            return false; // Must provide either userName, realName, or both
        }
        if (typeof registrationParameters.userName === "undefined") {
            registrationParameters.userName = "";
        }
        if (typeof registrationParameters.realName === "undefined") {
            registrationParameters.realName = "";
        }
        if (typeof registrationParameters.gender === "undefined" || registrationParameters.gender.length == 0) {
            registrationParameters.gender = "U";
        } else if (registrationParameters.gender != "M" && registrationParameters.gender != "F" && registrationParameters.gender != "U") {
            registrationParameters.gender = "U";
        }
        if (typeof registrationParameters.emailAddress === "undefined") {
            registrationParameters.emailAddress = "";
        }
        if (typeof registrationParameters.scope === "undefined") {
            registrationParameters.scope = "";
        }
        if (typeof registrationParameters.agreement === "undefined") {
            registrationParameters.agreement = "0";
        }
        if (typeof registrationParameters.idToken === "undefined") {
            registrationParameters.idToken = "";
        }
        if (typeof registrationParameters.avatarURL === "undefined") {
            registrationParameters.avatarURL = "";
        }
        if (typeof registrationParameters.dob === "undefined" || registrationParameters.dob.length == 0) {
            registrationParameters.dob = new Date();
            registrationParameters.dob = registrationParameters.dob.toISOString().slice(0, 9);
        } else if (registrationParameters.dob instanceof Date) {
            // if is date() then convert to string
            registrationParameters.dob = registrationParameters.dob.toISOString().slice(0, 9);
        }
        return sendRequest("UserLoginCoreg", {
            site_user_id: registrationParameters.siteUserId,
            user_name: registrationParameters.userName,
            real_name: registrationParameters.realName,
            email_address: registrationParameters.emailAddress,
            gender: registrationParameters.gender,
            dob: registrationParameters.dob,
            network_id: networkId,
            scope: registrationParameters.scope,
            agreement: registrationParameters.agreement,
            avatar_url: registrationParameters.avatarURL,
            id_token: registrationParameters.idToken
        },
        overRideCallBackFunction);
    },

    /**
     * Return the proper URL to use to show an avatar for a given user. The default is the default size and the current user.
     * @param {integer} size 0 small, 1 medium, 2 large
     * @param {integer} userId Id of the user.
     * @return {string} URL.
     */
    avatarURL: function (size, userId) {
        if (userId == 0) {
            userId = enginesis.loggedInUserInfo ? enginesis.loggedInUserInfo.user_id : 0;
        }
        // @todo: Size is determined by site_data, sites could have different sizes
        if (size < 0) {
            size = 0;
        } else if (size > 2) {
            size = 2;
        }
        return enginesis.siteResources.avatarImageURL + '?site_id=' + enginesis.siteId + '&user_id=' + userId + '&size=' + size;
    },

    /**
     * Get information about a specific quiz.
     * @param {integer} quiz_id
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    quizGet: function (quiz_id, overRideCallBackFunction) {
        return sendRequest("QuizGet", {game_id: quiz_id}, overRideCallBackFunction);
    },

    /**
     * Ask quiz service to begin playing a specific quiz given the quiz id. If the quiz-id does not exist
     * then an error is returned.
     * @param {integer} quiz_id
     * @param {integer} game_group_id
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    quizPlay: function (quiz_id, game_group_id, overRideCallBackFunction) {
        return sendRequest("QuizPlay", {game_id: quiz_id, game_group_id: game_group_id}, overRideCallBackFunction);
    },

    /**
     * Ask quiz service to begin playing the next quiz in a scheduled quiz series. This should always return at least
     * one quiz.
     * @param {integer} quiz_id if a specific quiz id is requested we try to return this one. If for some reason we cannot, the next quiz in the scheduled series is returned.
     * @param {integer} game_group_id quiz group id.
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    quizPlayScheduled: function (quiz_id, game_group_id, overRideCallBackFunction) {
        return sendRequest("QuizPlayScheduled", {game_id: quiz_id, game_group_id: game_group_id}, overRideCallBackFunction);
    },

    /**
     * Return a summary of quiz outcomes for the given quiz id.
     * @param {integer} quiz_id
     * @param {integer} game_group_id
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    quizOutcomesCountList: function(quiz_id, game_group_id, overRideCallBackFunction) {
        return sendRequest("QuizOutcomesCountList", {game_id: quiz_id, game_group_id: game_group_id}, overRideCallBackFunction);
    },

    /**
     * Submit the results of a completed quiz. Results is a JSON object we need to document.
     * @param {integer} quiz_id
     * @param {object} results
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    quizSubmit: function(quiz_id, results, overRideCallBackFunction) {
        return sendRequest("QuizSubmit", {game_id: quiz_id, results: results}, overRideCallBackFunction);
    },

    /**
     * When the user plays a question we record the event and the choice the user made. This helps us with question
     * usage statistics and allows us to track question consumption so the return visits to this quiz can provide
     * fresh questions for this user.
     * @param {integer} quiz_id
     * @param {integer} question_id
     * @param {integer} choice_id
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    quizQuestionPlayed: function(quiz_id, question_id, choice_id, overRideCallBackFunction) {
        return sendRequest("QuizQuestionPlayed", {game_id: quiz_id, question_id: question_id, choice_id: choice_id}, overRideCallBackFunction);
    },

    /**
     * Determine if the game_id in question is among the user's favorite games. This function will
     * return an answer right away by looking at the cached list of games. If a call back function is
     * provided, the server will be queried for a updated list of favorite games and the test
     * will be done asynchronously.
     *
     * @param {integer} game_id A game id to check, or null/0 to check the current game id.
     * @param {function} callBackFunction If provided, query the server then call this function with the result.
     * @returns {boolean} True if the requested game_id is a favorite game for this user.
     */
    isUserFavoriteGame: function (game_id, callBackFunction) {
        const gameId = parseInt(game_id, 10) || enginesis.gameId;
        const isFavorite = enginesis.favoriteGames.has(gameId);
        if (typeof callBackFunction === "function" && enginesis.favoriteGamesNextCheck < Date.now()) {
            enginesis.userFavoriteGamesList()
            .then(function(enginesisResult) {
                callBackFunction(gameId, enginesis.favoriteGames.has(gameId));
            });
        }
        return isFavorite;
    },

    /**
     * Get list of users favorite games. User must be logged in.
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    userFavoriteGamesList: function (overRideCallBackFunction) {
        // @todo: wait until timer expires? Or do it now because caller wants it now?
        // if (enginesis.favoriteGamesNextCheck < Date.now()) {
        enginesis.favoriteGamesNextCheck = Date.now() + 60000;
        return sendRequest("UserFavoriteGamesList", {}, overRideCallBackFunction);
    },

    /**
     * Assign a game-id to the list of user favorite games. User must be logged in.
     * @param {integer} game_id
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    userFavoriteGamesAssign: function(game_id, overRideCallBackFunction) {
        const serviceName = "UserFavoriteGamesAssign";
        const gameId = game_id || enginesis.gameId;
        const serviceParameters = {
            game_id: gameId
        };
        enginesis.favoriteGames.add(gameId);
        if ( ! enginesis.isUserLoggedIn) {
            const errorCode = "NOT_AUTHENTICATED";
            const errorMessage = "You must log in to update your favorite games.";
            anonymousUserSave();
            return immediateErrorResponse(serviceName, serviceParameters, errorCode, errorMessage, overRideCallBackFunction)
        } else {
            return sendRequest(serviceName, serviceParameters, overRideCallBackFunction);
        }
    },

    /**
     * Assign a list of game-ids to the list of user favorite games. User must be logged in. List is separated by commas.
     * @param {integer} game_id_list
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    userFavoriteGamesAssignList: function(game_id_list, overRideCallBackFunction) {
        var gameIdList = game_id_list.split(",");
        for (var i = 0; i < gameIdList.length; i ++) {
            enginesis.favoriteGames.add(gameIdList[i]);
        }
        var serviceName = "UserFavoriteGamesAssignList";
        var serviceParameters = {
            game_id_list: game_id_list,
            delimiter: ','
        };
        if ( ! enginesis.isUserLoggedIn) {
            const errorCode = "NOT_AUTHENTICATED";
            const errorMessage = "You must log in to update your favorite games.";
            anonymousUserSave();
            return immediateErrorResponse(serviceName, serviceParameters, errorCode, errorMessage, overRideCallBackFunction)
        } else {
            return sendRequest(serviceName, serviceParameters, overRideCallBackFunction);
        }
    },

    /**
     * Remove a game-id from the list of user favorite games. User must be logged in.
     * @param {integer|null} game_id
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    userFavoriteGamesUnassign: function(game_id, overRideCallBackFunction) {
        game_id = game_id || enginesis.gameId;
        enginesis.favoriteGames.delete(game_id);
        var serviceName = "UserFavoriteGamesUnassign";
        var serviceParameters = {
            game_id: game_id
        };
        if ( ! enginesis.isUserLoggedIn) {
            const errorCode = "NOT_AUTHENTICATED";
            const errorMessage = "You must log in to update your favorite games.";
            anonymousUserSave();
            return immediateErrorResponse(serviceName, serviceParameters, errorCode, errorMessage, overRideCallBackFunction)
        } else {
            return sendRequest(serviceName, serviceParameters, overRideCallBackFunction);
        }
    },

    /**
     * Remove a list of game-ids from the list of user favorite games. User must be logged in. List is separated by commas.
     * @param {integer} game_id_list
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    userFavoriteGamesUnassignList: function(game_id_list, overRideCallBackFunction) {
        var gameIdList = game_id_list.split(",");
        for (var i = 0; i < gameIdList.length; i ++) {
            enginesis.favoriteGames.delete(gameIdList[i]);
        }
        var serviceName = "UserFavoriteGamesUnassignList";
        var serviceParameters = {
            game_id_list: game_id_list,
            delimiter: ','
        };
        if ( ! enginesis.isUserLoggedIn) {
            const errorCode = "NOT_AUTHENTICATED";
            const errorMessage = "You must log in to update your favorite games.";
            anonymousUserSave();
            return immediateErrorResponse(serviceName, serviceParameters, errorCode, errorMessage, overRideCallBackFunction)
        } else {
            return sendRequest(serviceName, serviceParameters, overRideCallBackFunction);
        }
    },

    /**
     * Change the order of a game in the list of user favorites.
     * @param {integer} game_id
     * @param {integer} sort_order
     * @param {function} overRideCallBackFunction
     * @returns {Promise}
     */
    userFavoriteGamesMove: function(game_id, sort_order, overRideCallBackFunction) {
        return sendRequest("UserFavoriteGamesMove", {game_id: game_id, sort_order: sort_order}, overRideCallBackFunction);
    },

    anonymousUserSetDateLastVisit: function() {
        if (enginesis.anonymousUser == null) {
            anonymousUserLoad();
        }
        enginesis.anonymousUser.dateLastVisit = new Date();
    },

    /**
     * Set the user email address and save the user data.
     * @param {string} emailAddress
     * @param {boolean} ifChanged If true, only change the email if it changed. If false, only change the email if never set.
     */
    anonymousUserSetSubscriberEmail: function(emailAddress, ifChanged) {
        var priorValue;
        if (enginesis.anonymousUser == null) {
            anonymousUserLoad();
        }
        if (typeof ifChanged === "undefined") {
            ifChanged = true;
        }
        priorValue = enginesis.anonymousUser.subscriberEmail;
        if ((ifChanged && emailAddress != priorValue) || ( ! ifChanged && isEmpty(priorValue))) {
            enginesis.anonymousUser.subscriberEmail = emailAddress;
            anonymousUserSave();
        }
    },

    /**
     * Return the anonymous user email.
     * @returns {string}
     */
    anonymousUserGetSubscriberEmail: function() {
        if (enginesis.anonymousUser == null) {
            anonymousUserLoad();
        }
        return enginesis.anonymousUser.subscriberEmail;
    },

    /**
     * Set the user name and save the user data.
     * @param {string} userName
     * @param {boolean} ifChanged If true, only change the name if it changed. If false, only change the name if never set.
     */
    anonymousUserSetUserName: function(userName, ifChanged) {
        var priorValue;
        if (enginesis.anonymousUser == null) {
            anonymousUserLoad();
        }
        if (typeof ifChanged === "undefined") {
            ifChanged = true;
        }
        priorValue = enginesis.anonymousUser.userName;
        if ((ifChanged && userName != priorValue) || ( ! ifChanged && isEmpty(priorValue))) {
            enginesis.anonymousUser.userName = userName;
            anonymousUserSave();
        }
    },

    /**
     * Get the anonymous user name.
     * @returns {string}
     */
    anonymousUserGetUserName: function() {
        if (enginesis.anonymousUser == null) {
            anonymousUserLoad();
        }
        return enginesis.anonymousUser.userName;
    },

    /**
     * Set the user id and save the user data only if the userId has changed. If we already
     * have a userId associated with this client then keep it.
     * @param userId {integer}
     */
    anonymousUserSetId: function(userId) {
        if (enginesis.anonymousUser == null) {
            anonymousUserLoad();
        }
        if (typeof enginesis.anonymousUser.userId === "undefined" || enginesis.anonymousUser.userId < 10000) {
            enginesis.anonymousUser.userId = userId;
            anonymousUserSave();
        }
    },

    /**
     * Get the anonymous user id.
     * @returns {string}
     */
    anonymousUserGetId: function() {
        if (enginesis.anonymousUser == null) {
            anonymousUserLoad();
        }
        return enginesis.anonymousUser.userId || 0;
    },

    /**
     * Add a favorite game_id to the user favorite games list only if it does not already exist in the list.
     * @param {integer} gameId
     */
    anonymousUserAddFavoriteGame: function(gameId) {
        if (enginesis.anonymousUser == null) {
            anonymousUserLoad();
        }
        enginesis.anonymousUser.favoriteGames.add(gameId);
        anonymousUserSave();
    },

    /**
     * Add a gameId to the list of game_ids played by this user. If the game_id already exists it moves to
     * the top of the list.
     * @param {integer} gameId
     */
    anonymousUserGamePlayed: function(gameId) {
        if (enginesis.anonymousUser == null) {
            anonymousUserLoad();
        }
        enginesis.anonymousUser.gamesPlayed.add(gameId);
        anonymousUserSave();
    },

    // ===========================================================================================================
    // Conference services
    // ===========================================================================================================
    conferenceAssetRootPath: function(conferenceId) {
        return '//' + enginesis.serverHost + '/sites/' + enginesis.siteId + '/conf/' + conferenceId + '/';
    },

    conferenceGet: function(conferenceId, overRideCallBackFunction) {
        var visibleId;
        if (parseInt(conferenceId, 10) > 0) {
            visibleId = '';
        } else {
            visibleId = conferenceId;
            conferenceId = 0;
        }
        return sendRequest("ConferenceGet", {conference_id: conferenceId, visible_id: visibleId}, overRideCallBackFunction);
    },

    conferenceTopicGet: function(conferenceId, conferenceTopicId, overRideCallBackFunction) {
        var visibleId;
        if (parseInt(conferenceId, 10) > 0) {
            visibleId = '';
        } else {
            visibleId = conferenceId;
            conferenceId = 0;
        }
        return sendRequest("ConferenceTopicGet", {conference_id: conferenceId, visible_id: visibleId, conference_topic_id: conferenceTopicId}, overRideCallBackFunction);
    },

    conferenceTopicList: function(conferenceId, tags, startDate, endDate, startItem, numItems, overRideCallBackFunction) {
        var visibleId;
        if (parseInt(conferenceId, 10) > 0) {
            visibleId = '';
        } else {
            visibleId = conferenceId;
            conferenceId = 0;
        }
        return sendRequest("ConferenceTopicList", {conference_id: conferenceId, visible_id: visibleId, tags: tags, start_date: startDate, end_date: endDate, start_item: startItem, num_items: numItems}, overRideCallBackFunction);
    }
    };

    /* ----------------------------------------------------------------------------------
     * Setup for AMD, node, or standalone reference the enginesis object.
     * ----------------------------------------------------------------------------------*/
    if (typeof define === 'function' && define.amd) {
        define(function () { return enginesisExport; });
    } else if (typeof exports === 'object') {
        module.exports = enginesisExport;
    } else {
        var existingEnginesis = window.enginesis;
        enginesis.existingEnginesis = function () {
            window.enginesis = existingEnginesis;
            return this;
        };
        window.enginesis = enginesisExport;
    }
})(this);
