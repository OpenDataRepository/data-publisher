Clazz.declarePackage("javajs.api");
(function(){
var c$ = Clazz.declareType(javajs.api, "Interface", null);
c$.getInterface = Clazz.defineMethod(c$, "getInterface", 
function(name){
try {
var x = Clazz._4Name(name);
return (x == null ? null : x.newInstance());
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
System.out.println("Interface.java Error creating instance for " + name + ": \n" + e);
return null;
} else {
throw e;
}
}
}, "~S");
c$.getInstanceWithParams = Clazz.defineMethod(c$, "getInstanceWithParams", 
function(name, classes, params){
try {
var cl = Clazz._4Name(name);
return cl.getConstructor(classes).newInstance(params);
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
System.out.println("Interface.getInterfaceWithParams Error creating instance for " + name + ": \n" + e);
return null;
} else {
throw e;
}
}
}, "~S,~A,~A");
})();
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
