Clazz.declarePackage("JU");
Clazz.load(["JU.T3i"], "JU.P3i", null, function(){
var c$ = Clazz.declareType(JU, "P3i", JU.T3i);
c$.new3 = Clazz.defineMethod(c$, "new3", 
function(x, y, z){
var pt =  new JU.P3i();
pt.x = x;
pt.y = y;
pt.z = z;
return pt;
}, "~N,~N,~N");
});
;//5.0.1-v2 Tue Mar 12 13:10:23 CDT 2024
