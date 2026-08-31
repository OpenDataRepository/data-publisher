Clazz.declarePackage("J.adapter.readers.cif");
Clazz.load(["J.adapter.readers.cif.CifReader"], "J.adapter.readers.cif.Cif2Reader", ["J.adapter.readers.cif.Cif2DataParser"], function(){
var c$ = Clazz.declareType(J.adapter.readers.cif, "Cif2Reader", J.adapter.readers.cif.CifReader);
Clazz.overrideMethod(c$, "getCifDataParser", 
function(){
return  new J.adapter.readers.cif.Cif2DataParser().set(this, null, this.debugging);
});
});
;//5.0.1-v7 Mon Aug 24 09:29:37 CDT 2026
