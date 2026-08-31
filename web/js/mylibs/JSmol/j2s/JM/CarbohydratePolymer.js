Clazz.declarePackage("JM");
Clazz.load(["JM.BioPolymer"], "JM.CarbohydratePolymer", null, function(){
var c$ = Clazz.declareType(JM, "CarbohydratePolymer", JM.BioPolymer);
Clazz.makeConstructor(c$, 
function(monomers){
Clazz.superConstructor(this, JM.CarbohydratePolymer, [monomers, false]);
this.type = 3;
}, "~A");
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
