Clazz.declarePackage("JS");
Clazz.load(["JS.ScriptException"], "JS.ScriptInterruption", null, function(){
var c$ = Clazz.declareType(JS, "ScriptInterruption", JS.ScriptException);
Clazz.makeConstructor(c$, 
function(eval, why, millis){
Clazz.superConstructor(this, JS.ScriptInterruption, [eval, why, "!", millis == -2147483648 || eval.vwr.autoExit]);
if (why.startsWith("delay")) {
var pt = why.indexOf(":");
var script = (pt > 0 ? why.substring(pt + 1) : null);
eval.delayScriptAndRun(millis, script);
}}, "JS.ScriptEval,~S,~N");
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
