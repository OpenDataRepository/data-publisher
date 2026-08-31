Clazz.declarePackage("java.net");
(function(){
var c$ = Clazz.declareType(java.net, "URLDecoder", null);
c$.decode = Clazz.defineMethod(c$, "decode", 
function(s){
return decodeURIComponent(s);
}, "~S");
})();
;//5.0.1-v7 Fri Nov 21 04:39:22 CST 2025
