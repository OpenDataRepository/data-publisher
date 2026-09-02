Clazz.declarePackage("JS");
Clazz.load(["J.thread.JmolThread"], "JS.DialogThread", ["JV.Viewer"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.options = null;
this.label = null;
this.key = "cache:// DIALOG";
Clazz.instantialize(this, arguments);}, JS, "DialogThread", J.thread.JmolThread);
Clazz.makeConstructor(c$, 
function(){
Clazz.superConstructor (this, JS.DialogThread, []);
});
Clazz.defineMethod(c$, "initialize", 
function(eval, vwr, label, options){
this.setViewer(vwr, "DialogThread");
this.options = options;
this.label = label;
this.setEval(eval);
this.sc.pc--;
return this;
}, "J.api.JmolScriptEvaluator,JV.Viewer,~S,~A");
Clazz.overrideMethod(c$, "run1", 
function(mode){
while (true) switch (mode) {
case -1:
mode = 0;
break;
case 0:
if (this.stopped || this.eval.isStopped()) {
mode = -2;
break;
}if (JV.Viewer.jmolObject != null) JV.Viewer.jmolObject.promptAsynchronously(this, this.vwr.html5Applet, this.label, this.options);
return;
case -2:
this.resumeEval();
return;
}

}, "~N");
Clazz.defineMethod(c$, "setData", 
function(option){
var isCanceled = option == null || option.equals("#CANCELED#");
this.sc.parentContext.htFileCache.put(this.key, (isCanceled ? "#CANCELED#" : option));
this.run1(-2);
}, "~S");
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
