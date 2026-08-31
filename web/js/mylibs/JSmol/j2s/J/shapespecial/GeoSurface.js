Clazz.declarePackage("J.shapespecial");
Clazz.load(["J.shapespecial.Dots"], "J.shapespecial.GeoSurface", null, function(){
var c$ = Clazz.declareType(J.shapespecial, "GeoSurface", J.shapespecial.Dots);
Clazz.defineMethod(c$, "initShape", 
function(){
Clazz.superCall(this, J.shapespecial.GeoSurface, "initShape", []);
this.isSurface = true;
this.translucentAllowed = true;
});
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
