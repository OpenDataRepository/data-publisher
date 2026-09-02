Clazz.declarePackage("JV.binding");
Clazz.load(["JV.binding.JmolBinding"], "JV.binding.RasmolBinding", null, function(){
var c$ = Clazz.declareType(JV.binding, "RasmolBinding", JV.binding.JmolBinding);
Clazz.makeConstructor(c$, 
function(){
Clazz.superConstructor (this, JV.binding.RasmolBinding, []);
this.set("selectOrToggle");
});
Clazz.overrideMethod(c$, "setSelectBindings", 
function(){
this.bindAction(33040, 30);
this.bindAction(33041, 35);
});
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
