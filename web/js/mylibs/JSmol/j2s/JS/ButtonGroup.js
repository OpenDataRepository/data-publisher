Clazz.declarePackage("JS");
Clazz.load(null, "JS.ButtonGroup", ["JS.Component"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.id = null;
this.count = 0;
Clazz.instantialize(this, arguments);}, JS, "ButtonGroup", null);
Clazz.makeConstructor(c$, 
function(){
this.id = JS.Component.newID("bg");
});
Clazz.defineMethod(c$, "add", 
function(item){
this.count++;
(item).htmlName = this.id;
}, "J.api.SC");
Clazz.defineMethod(c$, "getButtonCount", 
function(){
return this.count;
});
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
