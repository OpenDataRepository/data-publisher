Clazz.declarePackage("J.jvxl.readers");
Clazz.load(["J.jvxl.readers.IsoMepReader"], "J.jvxl.readers.IsoMlpReader", null, function(){
var c$ = Clazz.declareType(J.jvxl.readers, "IsoMlpReader", J.jvxl.readers.IsoMepReader);
Clazz.makeConstructor(c$, 
function(){
Clazz.superConstructor (this, J.jvxl.readers.IsoMlpReader, []);
});
Clazz.overrideMethod(c$, "init", 
function(sg){
this.initIMR(sg);
this.type = "Mlp";
}, "J.jvxl.readers.SurfaceGenerator");
});
;//5.0.1-v2 Tue Mar 12 13:10:23 CDT 2024
