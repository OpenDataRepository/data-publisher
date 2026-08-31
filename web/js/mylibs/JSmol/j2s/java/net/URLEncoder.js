Clazz.declarePackage("java.net");
(function(){
var c$ = Clazz.declareType(java.net, "URLEncoder", null);
c$.encode = Clazz.defineMethod(c$, "encode", 
function(s){
return encodeURIComponent(s);
}, "~S");
})();
;//5.0.1-v7 Fri Nov 21 04:39:22 CST 2025
