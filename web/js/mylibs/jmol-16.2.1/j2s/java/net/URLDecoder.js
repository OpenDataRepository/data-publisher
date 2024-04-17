Clazz.declarePackage("java.net");
(function(){
var c$ = Clazz.declareType(java.net, "URLDecoder", null);
c$.decode = Clazz.defineMethod(c$, "decode", 
function(s){
return decodeURIComponent(s);
}, "~S");
})();
;//5.0.1-v2 Thu Feb 08 09:49:36 CST 2024
