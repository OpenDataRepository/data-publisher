Clazz.declarePackage("JV");
(function(){
var c$ = Clazz.decorateAsClass(function(){
this.x = -1000;
this.y = -1000;
this.modifiers = 0;
this.time = -1;
this.modelIndex = 0;
this.name = null;
this.keybuf = 0;
Clazz.instantialize(this, arguments);}, JV, "MouseState", null);
Clazz.makeConstructor(c$, 
function(name){
this.name = name;
}, "~S");
Clazz.defineMethod(c$, "setModelIndex", 
function(modelIndex){
this.modelIndex = modelIndex;
}, "~N");
Clazz.defineMethod(c$, "set", 
function(time, x, y, modifiers){
this.time = time;
this.x = x;
this.y = y;
this.modifiers = modifiers;
}, "~N,~N,~N,~N");
Clazz.defineMethod(c$, "setCurrent", 
function(current, clickCount){
this.time = current.time;
if (clickCount < 2) {
this.x = current.x;
this.y = current.y;
}this.modifiers = current.modifiers;
}, "JV.MouseState,~N");
Clazz.defineMethod(c$, "inRange", 
function(xyRange, x, y){
return (Math.abs(this.x - x) <= xyRange && Math.abs(this.y - y) <= xyRange);
}, "~N,~N,~N");
Clazz.defineMethod(c$, "check", 
function(xyRange, x, y, modifiers, time, delayMax){
return (this.modifiers == modifiers && (delayMax >= 2147483647 ? this.inRange(xyRange, x, y) : time - this.time < delayMax && time - this.time > 20));
}, "~N,~N,~N,~N,~N,~N");
Clazz.defineMethod(c$, "is", 
function(current){
return (current.x == this.x && current.y == this.y && current.time == this.time);
}, "JV.MouseState");
})();
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
