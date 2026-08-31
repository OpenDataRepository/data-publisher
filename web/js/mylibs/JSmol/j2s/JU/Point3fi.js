Clazz.declarePackage("JU");
Clazz.load(["JU.P3"], "JU.Point3fi", null, function(){
var c$ = Clazz.decorateAsClass(function(){
this.mi = -1;
this.i = 0;
this.sX = 0;
this.sY = 0;
this.sZ = 0;
this.sD = -1;
Clazz.instantialize(this, arguments);}, JU, "Point3fi", JU.P3, Cloneable);
c$.newPF = Clazz.defineMethod(c$, "newPF", 
function(p, i){
var pi =  new JU.Point3fi();
pi.setT(p);
pi.i = i;
return pi;
}, "JU.T3,~N");
c$.new4 = Clazz.defineMethod(c$, "new4", 
function(x, y, z, i){
var pi =  new JU.Point3fi();
pi.set(x, y, z);
pi.i = i;
return pi;
}, "~N,~N,~N,~N");
Clazz.defineMethod(c$, "copy", 
function(){
try {
return this.clone();
} catch (e) {
if (Clazz.exceptionOf(e,"CloneNotSupportedException")){
return null;
} else {
throw e;
}
}
});
Clazz.defineMethod(c$, "setPF", 
function(p){
this.x = p.x;
this.y = p.y;
this.z = p.z;
this.i = p.i;
}, "JU.Point3fi");
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
