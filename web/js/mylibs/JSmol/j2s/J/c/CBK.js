Clazz.declarePackage("J.c");
Clazz.load(["java.lang.Enum"], "J.c.CBK", ["JU.SB"], function(){
var c$ = Clazz.declareType(J.c, "CBK", Enum);
c$.getCallback = Clazz.defineMethod(c$, "getCallback", 
function(name){
name = name.toUpperCase();
var pt = name.indexOf("CALLBACK");
if (pt > 0) name = name.substring(0, pt);
for (var item, $item = 0, $$item = J.c.CBK.values(); $item < $$item.length && ((item = $$item[$item]) || true); $item++) if (item.name().equalsIgnoreCase(name)) return item;

return null;
}, "~S");
c$.getNameList = Clazz.defineMethod(c$, "getNameList", 
function(){
if (J.c.CBK.nameList == null) {
var names =  new JU.SB();
for (var item, $item = 0, $$item = J.c.CBK.values(); $item < $$item.length && ((item = $$item[$item]) || true); $item++) names.append(item.name().toLowerCase()).append("Callback;");

J.c.CBK.nameList = names.toString();
}return J.c.CBK.nameList;
});
c$.nameList = null;
Clazz.defineEnumConstant(c$, "ANIMFRAME", 0, []);
Clazz.defineEnumConstant(c$, "APPLETREADY", 1, []);
Clazz.defineEnumConstant(c$, "ATOMMOVED", 2, []);
Clazz.defineEnumConstant(c$, "AUDIO", 3, []);
Clazz.defineEnumConstant(c$, "CALCULATION", 4, []);
Clazz.defineEnumConstant(c$, "CLICK", 5, []);
Clazz.defineEnumConstant(c$, "DRAGDROP", 6, []);
Clazz.defineEnumConstant(c$, "ECHO", 7, []);
Clazz.defineEnumConstant(c$, "ERROR", 8, []);
Clazz.defineEnumConstant(c$, "EVAL", 9, []);
Clazz.defineEnumConstant(c$, "HOVER", 10, []);
Clazz.defineEnumConstant(c$, "IMAGE", 11, []);
Clazz.defineEnumConstant(c$, "LOADSTRUCT", 12, []);
Clazz.defineEnumConstant(c$, "MEASURE", 13, []);
Clazz.defineEnumConstant(c$, "MESSAGE", 14, []);
Clazz.defineEnumConstant(c$, "MINIMIZATION", 15, []);
Clazz.defineEnumConstant(c$, "MODELKIT", 16, []);
Clazz.defineEnumConstant(c$, "PICK", 17, []);
Clazz.defineEnumConstant(c$, "RESIZE", 18, []);
Clazz.defineEnumConstant(c$, "SCRIPT", 19, []);
Clazz.defineEnumConstant(c$, "SELECT", 20, []);
Clazz.defineEnumConstant(c$, "SERVICE", 21, []);
Clazz.defineEnumConstant(c$, "STRUCTUREMODIFIED", 22, []);
Clazz.defineEnumConstant(c$, "SYNC", 23, []);
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
