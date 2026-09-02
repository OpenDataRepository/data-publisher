Clazz.declarePackage("JS");
Clazz.load(["JU.SimpleUnitCell", "JU.P3", "JV.JC"], "JS.UnitCell", ["java.util.Hashtable", "JU.AU", "$.Lst", "$.M3", "$.M4", "$.Measure", "$.P4", "$.PT", "$.Quat", "$.V3", "J.api.Interface", "JS.Symmetry", "JU.BoxInfo", "$.Escape", "$.Point3fi"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.moreInfo = null;
this.name = "";
this.vertices = null;
this.fractionalOffset = null;
this.allFractionalRelative = false;
this.cartesianOffset = null;
this.unitCellMultiplier = null;
this.unitCellMultiplied = null;
this.f2c = null;
this.spinFrameFromCartXYZ = null;
this.spinFrameToCartXYZ = null;
Clazz.instantialize(this, arguments);}, JS, "UnitCell", JU.SimpleUnitCell, Cloneable);
Clazz.prepareFields (c$, function(){
this.cartesianOffset =  new JU.P3();
});
c$.fromOABC = Clazz.defineMethod(c$, "fromOABC", 
function(oabc, setRelative){
var c =  new JS.UnitCell();
if (oabc.length == 3) oabc =  Clazz.newArray(-1, [ new JU.P3(), oabc[0], oabc[1], oabc[2]]);
var parameters =  Clazz.newFloatArray(-1, [-1, 0, 0, 0, 0, 0, oabc[1].x, oabc[1].y, oabc[1].z, oabc[2].x, oabc[2].y, oabc[2].z, oabc[3].x, oabc[3].y, oabc[3].z]);
c.init(parameters);
c.allFractionalRelative = setRelative;
c.initUnitcellVertices();
c.setCartesianOffset(oabc[0]);
return c;
}, "~A,~B");
c$.fromParams = Clazz.defineMethod(c$, "fromParams", 
function(params, setRelative, slop){
var c =  new JS.UnitCell();
c.init(params);
c.initUnitcellVertices();
c.allFractionalRelative = setRelative;
c.setPrecision(slop);
if (params.length > 26) params[26] = slop;
return c;
}, "~A,~B,~N");
c$.cloneUnitCell = Clazz.defineMethod(c$, "cloneUnitCell", 
function(uc){
var ucnew = null;
try {
ucnew = uc.clone();
} catch (e) {
if (Clazz.exceptionOf(e,"CloneNotSupportedException")){
} else {
throw e;
}
}
return ucnew;
}, "JS.UnitCell");
Clazz.defineMethod(c$, "checkPeriodic", 
function(pt, packing, packing2){
var min;
var max;
if (Float.isNaN(packing)) {
min = -this.slop;
} else {
min = -packing;
}if (Float.isNaN(packing2)) {
max = 1 - this.slop;
} else {
max = 1 + packing2;
}switch (this.dimension) {
case 3:
if (pt.z < min || pt.z > max) return false;
case 2:
if (pt.y < min || pt.y > max) return false;
case 1:
if (pt.x < min || pt.x > max) return false;
}
return true;
}, "JU.P3,~N,~N");
Clazz.defineMethod(c$, "isWithinUnitCell", 
function(a, b, c, packing, pt){
if (Float.isNaN(packing)) packing = this.slop;
switch (this.dimension) {
case 3:
if (pt.z < c - 1 - packing || pt.z > c + packing) return false;
case 2:
if (pt.y < b - 1 - packing || pt.y > b + packing) return false;
case 1:
if (pt.x < a - 1 - packing || pt.x > a + packing) return false;
}
return true;
}, "~N,~N,~N,~N,JU.P3");
Clazz.defineMethod(c$, "dumpInfo", 
function(isDebug, multiplied){
var m = (multiplied ? this.getUnitCellMultiplied() : this);
if (m !== this) return m.dumpInfo(isDebug, false);
return "a=" + this.a + ", b=" + this.b + ", c=" + this.c + ", alpha=" + this.alpha + ", beta=" + this.beta + ", gamma=" + this.gamma + "\noabc=" + JU.Escape.eAP(this.getUnitCellVectors()) + "\nvolume=" + this.volume + (isDebug ? "\nfractional to cartesian: " + this.matrixFractionalToCartesian + "\ncartesian to fractional: " + this.matrixCartesianToFractional : "");
}, "~B,~B");
Clazz.defineMethod(c$, "fix000", 
function(x){
return (Math.abs(x) < 0.001 ? 0 : x);
}, "~N");
Clazz.defineMethod(c$, "fixFloor", 
function(d){
return (d == 1 ? 0 : d);
}, "~N");
Clazz.defineMethod(c$, "getCanonicalCopy", 
function(scale, withOffset){
var pts = this.getScaledCell(withOffset);
return JU.BoxInfo.getCanonicalCopy(pts, scale);
}, "~N,~B");
Clazz.defineMethod(c$, "getCanonicalCopyTrimmed", 
function(frac, scale){
var pts = this.getScaledCellMult(frac, true);
return JU.BoxInfo.getCanonicalCopy(pts, scale);
}, "JU.P3,~N");
Clazz.defineMethod(c$, "getCartesianOffset", 
function(){
return this.cartesianOffset;
});
Clazz.defineMethod(c$, "getCellWeight", 
function(pt){
var f = 1;
if (pt.x <= this.slop || pt.x >= 1 - this.slop) f /= 2;
if (pt.y <= this.slop || pt.y >= 1 - this.slop) f /= 2;
if (pt.z <= this.slop || pt.z >= 1 - this.slop) f /= 2;
return f;
}, "JU.P3");
Clazz.defineMethod(c$, "getConventionalUnitCell", 
function(latticeType, primitiveToCrystal){
var oabc = this.getUnitCellVectors();
if (!latticeType.equals("P") || primitiveToCrystal != null) this.toFromPrimitive(false, latticeType.charAt(0), oabc, primitiveToCrystal);
return oabc;
}, "~S,JU.M3");
Clazz.defineMethod(c$, "getEquivalentPoints", 
function(pt, flags, ops, list, i0, n0, dup0, periodicity, packing){
var fromfractional = (flags.indexOf("fromfractional") >= 0);
var tofractional = (flags.indexOf("tofractional") >= 0);
var adjustA = ((periodicity & 0x1) != 0);
var adjustB = ((periodicity & 0x2) != 0);
var adjustC = ((periodicity & 0x4) != 0);
var haveSpin = (pt.sD >= 0);
var v = (haveSpin ?  new JU.V3() : null);
if (list == null) list =  new JU.Lst();
var n = list.size();
var pf = JU.Point3fi.newPF(pt, pt.i);
if (!fromfractional) this.toFractional(pf, true);
for (var i = 0, nops = ops.length; i < nops; i++) {
var p = JU.Point3fi.newPF(pf, pt.i);
p.mi = i;
ops[i].rotTrans(p);
if (adjustA) p.x = this.fixFloor(p.x - Clazz.doubleToInt(Math.floor(p.x)));
if (adjustB) p.y = this.fixFloor(p.y - Clazz.doubleToInt(Math.floor(p.y)));
if (adjustC) p.z = this.fixFloor(p.z - Clazz.doubleToInt(Math.floor(p.z)));
if (haveSpin) {
v.set(pt.sX, pt.sY, pt.sZ);
(ops[i]).rotateSpin(v);
p.sX = Math.round(v.x);
p.sY = Math.round(v.y);
p.sZ = Math.round(v.z);
p.sD = pt.sD;
}list.addLast(p);
n++;
}
if (packing >= 0) {
if (!adjustC) {
var offset = JU.P3.new3(0, 0, 0.5);
for (var i = n0; i < n; i++) {
list.get(i).add(offset);
}
}if (!adjustB) {
var offset = JU.P3.new3(0, 0.5, 0);
for (var i = n0; i < n; i++) {
list.get(i).add(offset);
}
}if (!adjustA) {
var offset = JU.P3.new3(0.5, 0, 0);
for (var i = n0; i < n; i++) {
list.get(i).add(offset);
}
}for (var i = n0; i < n; i++) {
pf.setPF(pt = list.get(i));
this.unitizeRnd(pf);
if (pf.x == 0) {
list.addLast(JS.UnitCell.newPt(pt, 0, pf.y, pf.z, pf.i));
list.addLast(JS.UnitCell.newPt(pt, 1, pf.y, pf.z, pf.i));
if (pf.y == 0) {
list.addLast(JS.UnitCell.newPt(pt, 1, 1, pf.z, pf.i));
list.addLast(JS.UnitCell.newPt(pt, 0, 0, pf.z, pf.i));
if (pf.z == 0) {
list.addLast(JS.UnitCell.newPt(pt, 1, 1, 1, pf.i));
list.addLast(JS.UnitCell.newPt(pt, 0, 0, 0, pf.i));
}}}if (pf.y == 0) {
list.addLast(JS.UnitCell.newPt(pt, pf.x, 0, pf.z, pf.i));
list.addLast(JS.UnitCell.newPt(pt, pf.x, 1, pf.z, pf.i));
if (pf.z == 0) {
list.addLast(JS.UnitCell.newPt(pt, pf.x, 0, 0, pf.i));
list.addLast(JS.UnitCell.newPt(pt, pf.x, 1, 1, pf.i));
}}if (pf.z == 0) {
list.addLast(JS.UnitCell.newPt(pt, pf.x, pf.y, 0, pf.i));
list.addLast(JS.UnitCell.newPt(pt, pf.x, pf.y, 1, pf.i));
if (pf.x == 0) {
list.addLast(JS.UnitCell.newPt(pt, 0, pf.y, 0, pf.i));
list.addLast(JS.UnitCell.newPt(pt, 1, pf.y, 1, pf.i));
}}}
if (packing > 0) {
if (adjustA) {
n = list.size();
for (var i = n0; i < n; i++) {
pf.setT(pt = list.get(i));
if (pf.x < packing) list.addLast(pt = JS.UnitCell.newPt(pt, pf.x + 1, pf.y, pf.z, pf.i));
if (pf.x > 1 - packing) list.addLast(pt = JS.UnitCell.newPt(pt, pf.x - 1, pf.y, pf.z, pf.i));
}
}if (adjustB) {
n = list.size();
for (var i = n0; i < n; i++) {
pf.setT(list.get(i));
if (pf.y < packing) list.addLast(JS.UnitCell.newPt(pt, pf.x, pf.y + 1, pf.z, pf.i));
if (pf.y > 1 - packing) list.addLast(JS.UnitCell.newPt(pt, pf.x, pf.y - 1, pf.z, pf.i));
}
}if (adjustC) {
n = list.size();
for (var i = n0; i < n; i++) {
pf.setT(list.get(i));
if (pf.z < packing) list.addLast(JS.UnitCell.newPt(pt, pf.x, pf.y, pf.z + 1, pf.i));
if (pf.z > 1 - packing) list.addLast(JS.UnitCell.newPt(pt, pf.x, pf.y, pf.z - 1, pf.i));
}
}}n = list.size();
if (!adjustA) {
var offset = JU.P3.new3(-0.5, 0, 0);
for (var i = n0; i < n; i++) {
list.get(i).add(offset);
}
}if (!adjustB) {
var offset = JU.P3.new3(0, -0.5, 0);
for (var i = n0; i < n; i++) {
list.get(i).add(offset);
}
}if (!adjustC) {
var offset = JU.P3.new3(0, 0, -0.5);
for (var i = n0; i < n; i++) {
list.get(i).add(offset);
}
}}JS.UnitCell.removeDuplicates(list, i0, dup0, -1);
if (!tofractional) {
for (var i = list.size(); --i >= n0; ) this.toCartesian(list.get(i), true);

}return list;
}, "JU.Point3fi,~S,~A,JU.Lst,~N,~N,~N,~N,~N");
c$.newPt = Clazz.defineMethod(c$, "newPt", 
function(pt, x, y, z, i){
var p = JU.Point3fi.new4(x, y, z, i);
p.sX = pt.sX;
p.sY = pt.sY;
p.sZ = pt.sZ;
p.sD = pt.sD;
p.mi = pt.mi;
return p;
}, "JU.Point3fi,~N,~N,~N,~N");
Clazz.defineMethod(c$, "getFractionalOffset", 
function(){
return this.fractionalOffset;
});
Clazz.defineMethod(c$, "getInfo", 
function(){
var m = this.getUnitCellMultiplied();
if (m !== this) return m.getInfo();
var info =  new java.util.Hashtable();
var a =  Clazz.newFloatArray (18, 0);
System.arraycopy(this.getUnitCellAsArray(false), 0, a, 0, 18);
info.put("params", a);
info.put("oabc", this.getUnitCellVectors());
info.put("volume", Float.$valueOf(this.volume));
info.put("matFtoC", this.matrixFractionalToCartesian);
info.put("matCtoF", this.matrixCartesianToFractional);
info.put("dimension", Integer.$valueOf(this.dimension));
info.put("dimensionType", Integer.$valueOf(this.dimensionType));
info.put("isHexagonal", Boolean.$valueOf(this.getInfo(8) != 0));
info.put("isRhombohedral", Boolean.$valueOf(this.getInfo(9) != 0));
if (this.fractionalOffset != null) {
info.put("cartesianOffset", this.cartesianOffset);
info.put("fractionalOffset", this.fractionalOffset);
}if (this.unitCellMultiplier != null) {
info.put("unitCellMultiplier", this.unitCellMultiplier);
}if (this.unitCellMultiplied != null) {
info.put("unitCellMultiplied", this.unitCellMultiplied);
}info.put("slop", Float.$valueOf(this.slop));
return info;
});
Clazz.defineMethod(c$, "getQuaternionRotation", 
function(abc){
var a = JU.V3.newVsub(this.vertices[4], this.vertices[0]);
var b = JU.V3.newVsub(this.vertices[2], this.vertices[0]);
var c = JU.V3.newVsub(this.vertices[1], this.vertices[0]);
var x =  new JU.P3();
var v =  new JU.P3();
var mul = (abc.charAt(0) == '-' ? -1 : 1);
if (mul < 0) abc = abc.substring(1);
var abc0 = abc;
abc = JU.PT.rep(JU.PT.rep(JU.PT.rep(JU.PT.rep(JU.PT.rep(JU.PT.rep(abc, "ab", "A"), "bc", "B"), "ca", "C"), "ba", "D"), "cb", "E"), "ac", "F");
var isFace = !abc0.equals(abc);
var quadrant = (isFace ? 1 : 0);
if (abc.length == 2) {
quadrant = (abc.charAt(1)).charCodeAt(0) - 48;
abc = abc.substring(0, 1);
}var isEven = (quadrant % 2 == 0);
var axis = "abcABCDEF".indexOf(abc);
var v1;
var v2;
var v3;
switch (axis) {
case 7:
mul = -mul;
case 4:
a.cross(c, b);
quadrant = ((5 - quadrant) % 4) + 1;
case 0:
default:
v1 = a;
v2 = c;
v3 = b;
break;
case 8:
mul = -mul;
case 5:
mul = -mul;
b.cross(c, a);
quadrant = ((2 + quadrant) % 4) + 1;
case 1:
v1 = b;
v2 = a;
v3 = c;
mul = -mul;
break;
case 3:
mul = -mul;
case 6:
c.cross(a, b);
if (isEven) quadrant = 6 - quadrant;
case 2:
v1 = c;
v2 = a;
v3 = b;
if (!isFace && quadrant > 0) {
quadrant = 5 - quadrant;
}break;
}
if (quadrant > 0) {
if (mul > 0 != isEven) {
v2 = v3;
v1.scale(-1);
}}switch (quadrant) {
case 0:
default:
case 1:
break;
case 2:
v1.scale(-1);
v2.scale(-1);
break;
case 3:
v2.scale(-1);
break;
case 4:
v1.scale(-1);
break;
}
x.cross(v1, v2);
v.cross(x, v1);
return JU.Quat.getQuaternionFrame(null, v, x).inv();
}, "~S");
Clazz.defineMethod(c$, "getScaledCell", 
function(withOffset){
return this.getScaledCellMult(null, withOffset);
}, "~B");
Clazz.defineMethod(c$, "getScaledCellMult", 
function(mult, withOffset){
var pts =  new Array(8);
var cell0 = null;
var cell1 = null;
var isFrac = (mult != null);
if (!isFrac) mult = this.unitCellMultiplier;
if (withOffset && mult != null && mult.z == 0) {
cell0 =  new JU.P3();
cell1 =  new JU.P3();
JU.SimpleUnitCell.ijkToPoint3f(Clazz.floatToInt(mult.x), cell0, 0, 0);
JU.SimpleUnitCell.ijkToPoint3f(Clazz.floatToInt(mult.y), cell1, 0, 0);
cell1.sub(cell0);
}var scale = (isFrac || mult == null || mult.z == 0 ? 1 : Math.abs(mult.z));
for (var i = 0; i < 8; i++) {
var pt = pts[i] = JU.P3.newP(JU.BoxInfo.unitCubePoints[i]);
if (cell0 != null) {
pts[i].add3(cell0.x + cell1.x * pt.x, cell0.y + cell1.y * pt.y, cell0.z + cell1.z * pt.z);
} else if (isFrac) {
pt.scaleT(mult);
}pts[i].scale(scale);
this.matrixFractionalToCartesian.rotTrans(pt);
if (!withOffset) pt.sub(this.cartesianOffset);
}
return pts;
}, "JU.T3,~B");
Clazz.defineMethod(c$, "getState", 
function(){
var s = "";
if (this.fractionalOffset != null && this.fractionalOffset.lengthSquared() != 0) s += "  unitcell offset " + JU.Escape.eP(this.fractionalOffset) + ";\n";
if (this.unitCellMultiplier != null) s += "  unitcell range " + JU.SimpleUnitCell.escapeMultiplier(this.unitCellMultiplier) + ";\n";
return s;
});
Clazz.defineMethod(c$, "getTensor", 
function(vwr, parBorU){
var t = (J.api.Interface.getUtil("Tensor", vwr, "file"));
if (parBorU[0] == 0 && parBorU[1] == 0 && parBorU[2] == 0) {
var f = parBorU[7];
var eigenValues =  Clazz.newFloatArray(-1, [f, f, f]);
return t.setFromEigenVectors(JS.UnitCell.unitVectors, eigenValues, "iso", "Uiso=" + f, null);
}t.parBorU = parBorU;
var Bcart =  Clazz.newDoubleArray (6, 0);
var ortepType = Clazz.floatToInt(parBorU[6]);
if (ortepType == 12) {
Bcart[0] = parBorU[0] * 19.739208802178716;
Bcart[1] = parBorU[1] * 19.739208802178716;
Bcart[2] = parBorU[2] * 19.739208802178716;
Bcart[3] = parBorU[3] * 19.739208802178716 * 2;
Bcart[4] = parBorU[4] * 19.739208802178716 * 2;
Bcart[5] = parBorU[5] * 19.739208802178716 * 2;
parBorU[7] = (parBorU[0] + parBorU[1] + parBorU[3]) * 26.318945;
} else {
var isFractional = (ortepType == 4 || ortepType == 5 || ortepType == 8 || ortepType == 9);
var cc = 2 - (ortepType % 2);
var dd = (ortepType == 8 || ortepType == 9 || ortepType == 10 ? 19.739208802178716 : ortepType == 4 || ortepType == 5 ? 0.25 : ortepType == 2 || ortepType == 3 ? Math.log(2) : 1);
var B11 = parBorU[0] * dd * (isFractional ? this.a_ * this.a_ : 1);
var B22 = parBorU[1] * dd * (isFractional ? this.b_ * this.b_ : 1);
var B33 = parBorU[2] * dd * (isFractional ? this.c_ * this.c_ : 1);
var B12 = parBorU[3] * dd * (isFractional ? this.a_ * this.b_ : 1) * cc;
var B13 = parBorU[4] * dd * (isFractional ? this.a_ * this.c_ : 1) * cc;
var B23 = parBorU[5] * dd * (isFractional ? this.b_ * this.c_ : 1) * cc;
parBorU[7] = Math.pow(B11 / 19.739208802178716 / this.a_ / this.a_ * B22 / 19.739208802178716 / this.b_ / this.b_ * B33 / 19.739208802178716 / this.c_ / this.c_, 0.3333);
Bcart[0] = this.a * this.a * B11 + this.b * this.b * this.cosGamma * this.cosGamma * B22 + this.c * this.c * this.cosBeta * this.cosBeta * B33 + this.a * this.b * this.cosGamma * B12 + this.b * this.c * this.cosGamma * this.cosBeta * B23 + this.a * this.c * this.cosBeta * B13;
Bcart[1] = this.b * this.b * this.sinGamma * this.sinGamma * B22 + this.c * this.c * this.cA_ * this.cA_ * B33 + this.b * this.c * this.cA_ * this.sinGamma * B23;
Bcart[2] = this.c * this.c * this.cB_ * this.cB_ * B33;
Bcart[3] = 2 * this.b * this.b * this.cosGamma * this.sinGamma * B22 + 2 * this.c * this.c * this.cA_ * this.cosBeta * B33 + this.a * this.b * this.sinGamma * B12 + this.b * this.c * (this.cA_ * this.cosGamma + this.sinGamma * this.cosBeta) * B23 + this.a * this.c * this.cA_ * B13;
Bcart[4] = 2 * this.c * this.c * this.cB_ * this.cosBeta * B33 + this.b * this.c * this.cosGamma * B23 + this.a * this.c * this.cB_ * B13;
Bcart[5] = 2 * this.c * this.c * this.cA_ * this.cB_ * B33 + this.b * this.c * this.cB_ * this.sinGamma * B23;
}return t.setFromThermalEquation(Bcart, JU.Escape.eAF(parBorU));
}, "JV.Viewer,~A");
Clazz.defineMethod(c$, "getUnitCellMultiplied", 
function(){
if (this.unitCellMultiplier == null || this.unitCellMultiplier.z > 0 && this.unitCellMultiplier.z == Clazz.floatToInt(this.unitCellMultiplier.z)) return this;
if (this.unitCellMultiplied == null) {
var pts = JU.BoxInfo.toOABC(this.getScaledCell(true), null);
this.unitCellMultiplied = JS.UnitCell.fromOABC(pts, false);
}return this.unitCellMultiplied;
});
Clazz.defineMethod(c$, "getUnitCellMultiplier", 
function(){
return this.unitCellMultiplier;
});
Clazz.defineMethod(c$, "isStandard", 
function(){
return (this.unitCellMultiplier == null || this.unitCellMultiplier.x == this.unitCellMultiplier.y);
});
Clazz.defineMethod(c$, "getUnitCellVectors", 
function(){
var m = this.matrixFractionalToCartesian;
return  Clazz.newArray(-1, [JU.P3.newP(this.cartesianOffset), JU.P3.new3(this.fix000(m.m00), this.fix000(m.m10), this.fix000(m.m20)), JU.P3.new3(this.fix000(m.m01), this.fix000(m.m11), this.fix000(m.m21)), JU.P3.new3(this.fix000(m.m02), this.fix000(m.m12), this.fix000(m.m22))]);
});
c$.toTrm = Clazz.defineMethod(c$, "toTrm", 
function(transform, trm){
if (trm == null) trm =  new JU.M4();
JS.UnitCell.getMatrixAndUnitCell(null, null, transform, trm);
return trm;
}, "~S,JU.M4");
c$.getMatrixAndUnitCell = Clazz.defineMethod(c$, "getMatrixAndUnitCell", 
function(vwr, uc, def, retMatrix){
if (def == null) def = "a,b,c";
var m;
var pts =  new Array(4);
var pt = pts[0] = JU.P3.new3(0, 0, 0);
pts[1] = JU.P3.new3(1, 0, 0);
pts[2] = JU.P3.new3(0, 1, 0);
pts[3] = JU.P3.new3(0, 0, 1);
var m3 =  new JU.M3();
if (JU.AU.isAD(def)) {
return JU.SimpleUnitCell.setAbcFromParams(def, pts);
}var isString = (typeof(def)=='string');
if (isString && (def).indexOf("(") >= 0) def = JU.SimpleUnitCell.parseSimpleMath(vwr, def);
if (isString && (def).charAt(0) == '[') {
def = JU.Escape.unescapeMatrix(def);
if (Clazz.instanceOf(def,"JU.M3")) {
def = JU.M4.newMV(def,  new JU.P3());
} else if (!(Clazz.instanceOf(def,"JU.M4"))) {
return null;
}if (retMatrix != null) {
retMatrix.setM4(def);
retMatrix = null;
}isString = false;
}if (isString) {
var sdef = def;
var strans;
var strans2 = null;
if (sdef.indexOf("a=") == 0) return JU.SimpleUnitCell.setAbc(sdef, null, pts);
if (sdef.indexOf(">") > 0) {
if (uc != null || retMatrix == null) return null;
var sdefs = sdef.$plit(">");
retMatrix.setIdentity();
var m4 =  new JU.M4();
for (var i = sdefs.length; --i >= 0; ) {
JS.UnitCell.getMatrixAndUnitCell(null, null, sdefs[i], m4);
retMatrix.mul2(m4, retMatrix);
}
return pts;
}var ret =  new Array(1);
var ptc = sdef.indexOf(";");
if (ptc >= 0) {
strans = sdef.substring(ptc + 1).trim();
sdef = sdef.substring(0, ptc);
ret[0] = sdef;
strans2 = JS.UnitCell.fixABC(ret);
if (sdef !== ret[0]) {
sdef = ret[0];
}} else if (sdef.equals("a,b,c")) {
strans = null;
} else {
if (sdef.indexOf("w") > 0) {
sdef = sdef.$replace('u', 'x').$replace('v', 'y').$replace('w', 'z');
}ret[0] = sdef;
strans = JS.UnitCell.fixABC(ret);
sdef = ret[0];
}sdef += ";0,0,0";
while (sdef.startsWith("!!")) sdef = sdef.substring(2);

var isRev = sdef.startsWith("!");
if (isRev) sdef = sdef.substring(1);
if (sdef.equals("r;0,0,0")) sdef = "2/3a+1/3b+1/3c,-1/3a+1/3b+1/3c,-1/3a-2/3b+1/3c" + sdef.substring(1);
 else if (sdef.equals("h;0,0,0")) sdef = "a-b,b-c,a+b+c" + sdef.substring(1);
var isABC = sdef.indexOf("x") < 0 && (sdef.indexOf("a") >= 0 || sdef.indexOf("b") >= 0 || sdef.indexOf("c") >= 0);
if (isABC && sdef.indexOf("*") >= 0 && uc != null) {
var mSpinPp = JU.M4.newM4(null);
var oabc = JS.UnitCell.getMatrixAndUnitCell(vwr, uc, "a,b,c", mSpinPp);
JS.UnitCell.getMatrixAndUnitCell(vwr, null, sdef.$replace('*', ' '), mSpinPp);
var flags =  Clazz.newBooleanArray(-1, [(sdef.indexOf("a*") >= 0), (sdef.indexOf("b*") >= 0), (sdef.indexOf("c*") >= 0)]);
m = JU.M4.newM4(null);
JS.UnitCell.adjustForReciprocal(uc, oabc, mSpinPp, flags, m, pts);
uc = null;
} else {
var symTemp =  new JS.Symmetry();
symTemp.setSpaceGroup(false);
var i = symTemp.addSpaceGroupOperation("=" + sdef, 0);
if (i < 0) return null;
m = symTemp.getSpaceGroupOperation(i);
(m).doFinalize();
var t =  new JU.P3();
JS.UnitCell.addTrans(strans, t);
JS.UnitCell.addTrans(strans2, t);
m.setTranslation(t);
}if (isABC) {
m.transpose33();
}if (isRev) {
m.invert();
}if (retMatrix != null) {
retMatrix.setM4(m);
}if (uc == null) return pts;
} else if (retMatrix != null || uc == null) {
return null;
} else if (Clazz.instanceOf(def,"JU.M3")) {
m = JU.M4.newMV(def,  new JU.P3());
} else if (Clazz.instanceOf(def,"JU.M4")) {
m = def;
} else {
m = (def)[0];
m.getRotationScale(m3);
m.rotTrans(pt);
uc.toCartesian(pt, false);
for (var i = 1; i < 4; i++) {
m3.rotate(pts[i]);
uc.toCartesian(pts[i], true);
}
return pts;
}m.getRotationScale(m3);
m.getTranslation(pt);
uc.toCartesian(pt, false);
for (var i = 1; i < 4; i++) {
m3.rotate(pts[i]);
uc.toCartesian(pts[i], true);
}
return pts;
}, "JV.Viewer,JU.SimpleUnitCell,~O,JU.M4");
c$.adjustForReciprocal = Clazz.defineMethod(c$, "adjustForReciprocal", 
function(uc, oabc, mSpinPp, flags, mRet, pts){
var recipOABC =  new Array(4);
JU.SimpleUnitCell.getReciprocal(oabc, recipOABC, 1);
var t =  new JU.P3();
var abc =  Clazz.newFloatArray (4, 0);
for (var i = 1; i <= 3; i++) {
var vnew =  new JU.P3();
mSpinPp.getColumn(i - 1, abc);
for (var j = 0; j < 3; j++) {
if (abc[j] == 0) continue;
vnew.scaleAdd2(abc[j], (flags[j] ? recipOABC[1 + j] : oabc[1 + j]), vnew);
}
pts[i] = vnew;
t.setP(vnew);
uc.toFractional(t, true);
mRet.setColumn4(i - 1, JS.UnitCell.fixZero(t.x, 1e-10), JS.UnitCell.fixZero(t.y, 1e-10), JS.UnitCell.fixZero(t.z, 1e-10), 0);
}
}, "JU.SimpleUnitCell,~A,JU.M4,~A,JU.M4,~A");
c$.fixZero = Clazz.defineMethod(c$, "fixZero", 
function(x, err){
return (Math.abs(x) < err ? 0 : x);
}, "~N,~N");
c$.addTrans = Clazz.defineMethod(c$, "addTrans", 
function(strans, t){
if (strans == null) return;
var atrans = JU.PT.split(strans, ",");
var ftrans =  Clazz.newFloatArray (3, 0);
if (atrans.length == 3) {
for (var j = 0; j < 3; j++) {
var s = atrans[j];
var sfpt = s.indexOf("/");
if (sfpt >= 0) {
ftrans[j] = JU.PT.parseFloat(s.substring(0, sfpt)) / JU.PT.parseFloat(s.substring(sfpt + 1));
} else {
ftrans[j] = JU.PT.parseFloat(s);
}}
}t.add3(ftrans[0], ftrans[1], ftrans[2]);
}, "~S,JU.P3");
c$.fixABC = Clazz.defineMethod(c$, "fixABC", 
function(ret){
var tokens = JU.PT.split(ret[0], ",");
if (tokens.length != 3) return null;
var trans = "";
var abc = "";
var haveT = false;
for (var i = 0; i < 3; i++) {
var a = tokens[i];
var p;
var n = 0;
for (p = a.length; --p >= 0; ) {
var c = a.charAt(p);
switch ((c).charCodeAt(0)) {
default:
if (c >= 'a') p = 0;
break;
case 43:
n = 1;
case 45:
p = -p;
break;
}
}
p = -1 - p;
if (p == 0) {
trans += ",0";
abc += "," + a;
} else {
haveT = true;
trans += "," + a.substring(p + n);
abc += "," + a.substring(0, p);
}}
ret[0] = abc.substring(1);
return (haveT ? trans.substring(1) : null);
}, "~A");
Clazz.defineMethod(c$, "getVertices", 
function(){
return this.vertices;
});
Clazz.defineMethod(c$, "hasOffset", 
function(){
return (this.fractionalOffset != null && this.fractionalOffset.lengthSquared() != 0);
});
Clazz.defineMethod(c$, "initOrientation", 
function(mat){
if (mat == null) return;
var m =  new JU.M4();
m.setToM3(mat);
this.matrixFractionalToCartesian.mul2(m, this.matrixFractionalToCartesian);
this.matrixCartesianToFractional.setM4(this.matrixFractionalToCartesian).invert();
this.initUnitcellVertices();
}, "JU.M3");
Clazz.defineMethod(c$, "initUnitcellVertices", 
function(){
if (this.matrixFractionalToCartesian == null) return;
this.matrixCtoFNoOffset = JU.M4.newM4(this.matrixCartesianToFractional);
this.matrixFtoCNoOffset = JU.M4.newM4(this.matrixFractionalToCartesian);
this.vertices =  new Array(8);
for (var i = 8; --i >= 0; ) this.vertices[i] = this.matrixFractionalToCartesian.rotTrans2(JU.BoxInfo.unitCubePoints[i],  new JU.P3());

});
Clazz.defineMethod(c$, "isSameAs", 
function(f2c2){
if (f2c2 == null) return false;
var f2c = this.getF2C();
for (var i = 0; i < 3; i++) {
for (var j = 0; j < 4; j++) {
if (!JU.SimpleUnitCell.approx0(f2c[i][j] - f2c2[i][j])) return false;
}
}
return true;
}, "~A");
Clazz.defineMethod(c$, "getF2C", 
function(){
if (this.f2c == null) {
this.f2c =  Clazz.newFloatArray (3, 4, 0);
for (var i = 0; i < 3; i++) this.matrixFractionalToCartesian.getRow(i, this.f2c[i]);

}return this.f2c;
});
Clazz.defineMethod(c$, "setCartesianOffset", 
function(origin){
this.cartesianOffset.setT(origin);
this.matrixFractionalToCartesian.m03 = this.cartesianOffset.x;
this.matrixFractionalToCartesian.m13 = this.cartesianOffset.y;
this.matrixFractionalToCartesian.m23 = this.cartesianOffset.z;
var wasOffset = this.hasOffset();
this.fractionalOffset = JU.P3.newP(this.cartesianOffset);
this.matrixCartesianToFractional.rotate(this.fractionalOffset);
this.matrixCartesianToFractional.m03 = -this.fractionalOffset.x;
this.matrixCartesianToFractional.m13 = -this.fractionalOffset.y;
this.matrixCartesianToFractional.m23 = -this.fractionalOffset.z;
if (this.allFractionalRelative) {
this.matrixCtoFNoOffset.setM4(this.matrixCartesianToFractional);
this.matrixFtoCNoOffset.setM4(this.matrixFractionalToCartesian);
}if (!wasOffset && this.fractionalOffset.lengthSquared() == 0) this.fractionalOffset = null;
this.f2c = null;
}, "JU.T3");
Clazz.defineMethod(c$, "setOffset", 
function(pt){
if (pt == null) return;
this.unitCellMultiplied = null;
var pt4 = (Clazz.instanceOf(pt,"JU.T4") ? pt : null);
var w = (pt4 == null ? 1.4E-45 : pt4.w);
var isCell555P4 = (w > 999999);
if (pt4 != null ? w <= 0 || isCell555P4 : pt.x >= 100 || pt.y >= 100) {
this.unitCellMultiplier = (pt.z == 0 && pt.x == pt.y && !isCell555P4 ? null : isCell555P4 ? JU.P4.newPt(pt4) : JU.P3.newP(pt));
this.unitCellMultiplied = null;
if (pt4 == null || pt4.w == 0 || isCell555P4) return;
}if (this.hasOffset() || pt.lengthSquared() > 0) {
this.fractionalOffset = JU.P3.newP(pt);
}this.matrixCartesianToFractional.m03 = -pt.x;
this.matrixCartesianToFractional.m13 = -pt.y;
this.matrixCartesianToFractional.m23 = -pt.z;
this.cartesianOffset.setT(pt);
this.matrixFractionalToCartesian.rotate(this.cartesianOffset);
this.matrixFractionalToCartesian.m03 = this.cartesianOffset.x;
this.matrixFractionalToCartesian.m13 = this.cartesianOffset.y;
this.matrixFractionalToCartesian.m23 = this.cartesianOffset.z;
if (this.allFractionalRelative) {
this.matrixCtoFNoOffset.setM4(this.matrixCartesianToFractional);
this.matrixFtoCNoOffset.setM4(this.matrixFractionalToCartesian);
}this.f2c = null;
}, "JU.T3");
Clazz.defineMethod(c$, "toFromPrimitive", 
function(toPrimitive, type, uc, primitiveToCrystal){
var offset = uc.length - 3;
var mf = null;
if (type == 'r' || primitiveToCrystal == null) {
switch ((type).charCodeAt(0)) {
default:
return false;
case 114:
JU.SimpleUnitCell.getReciprocal(uc, uc, 1);
return true;
case 80:
toPrimitive = true;
case 65:
case 66:
case 67:
case 82:
case 73:
case 70:
mf = JS.UnitCell.getPrimitiveTransform(type);
break;
}
if (!toPrimitive) mf.invert();
} else {
mf = JU.M3.newM3(primitiveToCrystal);
if (toPrimitive) mf.invert();
}for (var i = uc.length; --i >= offset; ) {
var p = uc[i];
this.toFractional(p, true);
mf.rotate(p);
this.toCartesian(p, true);
}
return true;
}, "~B,~S,~A,JU.M3");
c$.getPrimitiveTransform = Clazz.defineMethod(c$, "getPrimitiveTransform", 
function(type){
switch ((type).charCodeAt(0)) {
case 80:
return JU.M3.newA9( Clazz.newFloatArray(-1, [1, 0, 0, 0, 1, 0, 0, 0, 1]));
case 65:
return JU.M3.newA9( Clazz.newFloatArray(-1, [1, 0, 0, 0, 0.5, 0.5, 0, -0.5, 0.5]));
case 66:
return JU.M3.newA9( Clazz.newFloatArray(-1, [0.5, 0, 0.5, 0, 1, 0, -0.5, 0, 0.5]));
case 67:
return JU.M3.newA9( Clazz.newFloatArray(-1, [0.5, 0.5, 0, -0.5, 0.5, 0, 0, 0, 1]));
case 82:
return JU.M3.newA9( Clazz.newFloatArray(-1, [0.6666667, -0.33333334, -0.33333334, 0.33333334, 0.33333334, -0.6666667, 0.33333334, 0.33333334, 0.33333334]));
case 73:
return JU.M3.newA9( Clazz.newFloatArray(-1, [-0.5, .5, .5, .5, -0.5, .5, .5, .5, -0.5]));
case 70:
return JU.M3.newA9( Clazz.newFloatArray(-1, [0, 0.5, 0.5, 0.5, 0, 0.5, 0.5, 0.5, 0]));
}
return null;
}, "~S");
Clazz.defineMethod(c$, "toUnitCell", 
function(pt, offset){
if (this.matrixCartesianToFractional == null) return;
if (offset == null) {
this.matrixCartesianToFractional.rotTrans(pt);
this.unitize(pt);
this.matrixFractionalToCartesian.rotTrans(pt);
} else {
this.matrixCtoFNoOffset.rotTrans(pt);
this.unitize(pt);
pt.add(offset);
this.matrixFtoCNoOffset.rotTrans(pt);
}}, "JU.T3,JU.T3");
Clazz.defineMethod(c$, "toUnitCellRnd", 
function(pt, offset){
if (this.matrixCartesianToFractional == null) return;
if (offset == null) {
this.matrixCartesianToFractional.rotTrans(pt);
this.unitizeRnd(pt);
this.matrixFractionalToCartesian.rotTrans(pt);
} else {
this.matrixCtoFNoOffset.rotTrans(pt);
this.unitizeRnd(pt);
pt.add(offset);
this.matrixFtoCNoOffset.rotTrans(pt);
}}, "JU.T3,JU.T3");
Clazz.defineMethod(c$, "unitize", 
function(pt){
this.unitizeDim(this.dimension, pt);
}, "JU.T3");
Clazz.defineMethod(c$, "unitizeRnd", 
function(pt){
JU.SimpleUnitCell.unitizeDimRnd(this.dimension, pt, this.slop);
}, "JU.T3");
c$.removeDuplicates = Clazz.defineMethod(c$, "removeDuplicates", 
function(list, i0, n0, n){
if (n < 0) n = list.size();
for (var i = i0; i < n; i++) {
var p = list.get(i);
for (var j = Math.max(i + 1, n0); j < n; j++) {
if (list.get(j).distanceSquared(p) < 1.96E-6) {
list.removeItemAt(j);
n--;
j--;
}}
}
}, "JU.Lst,~N,~N,~N");
Clazz.defineMethod(c$, "getCenter", 
function(periodicity){
var center =  new JU.P3();
var off = this.getCartesianOffset();
var pts = this.getVertices();
var j2;
var jd;
switch (periodicity) {
default:
case 0x7:
j2 = 8;
jd = 1;
break;
case 0x3:
j2 = 8;
jd = 2;
break;
case 0x4:
j2 = 2;
jd = 1;
break;
case 0x2:
j2 = 3;
jd = 2;
break;
case 0x1:
j2 = 8;
jd = 4;
break;
}
var n = 0;
for (var j = 0; j < j2; j += jd) {
center.add(pts[j]);
center.add(off);
n++;
}
center.scale(1 / n);
return center;
}, "~N");
Clazz.defineMethod(c$, "setSpinAxisAngle", 
function(aa){
if (this.moreInfo == null) {
this.moreInfo =  new JU.Lst();
}var s = "rotation_axis_xyz=";
var a = "rotation_angle=";
var ptAxis = -1;
var ptAngle = -1;
for (var i = this.moreInfo.size(); --i >= 0 && (ptAxis < 0 || ptAngle < 0); ) {
var s0 = this.moreInfo.get(i);
if (s0.startsWith(s)) {
ptAxis = i;
} else if (s0.startsWith(a)) {
ptAngle = i;
}}
var q = JU.Quat.newAA(aa);
var v =  new JU.V3();
if (JS.UnitCell.v0 == null) JS.UnitCell.v0 = JU.V3.new3(3.141592653589793, 2.718281828459045, Math.sqrt(2));
v = q.getNormalDirected(JS.UnitCell.v0);
JU.V3.newV(aa);
this.toFractional(v, true);
v.normalize();
var f = this.getAxisMultiplier(v);
s += "" + Math.round(v.x * f) + "," + Math.round(v.y * f) + "," + Math.round(v.z * f);
if (ptAxis < 0) {
this.moreInfo.addLast(s);
if (ptAngle > ptAxis) ptAngle++;
} else {
this.moreInfo.set(ptAxis, s);
}f = Clazz.doubleToInt(Math.round(q.getThetaDirectedV(JS.UnitCell.v0) * 1000) / 1000);
s = a + (f == Math.round(f) ? "" + Math.round(f) : JS.UnitCell.round000(f));
if (ptAngle < 0) {
this.moreInfo.addLast(s);
} else {
this.moreInfo.set(ptAngle, s);
}}, "JU.A4");
Clazz.defineMethod(c$, "getAxisMultiplier", 
function(v){
if (JS.UnitCell.approx00(v.x - Math.round(v.x)) && JS.UnitCell.approx00(v.y - Math.round(v.y)) && JS.UnitCell.approx00(v.z - Math.round(v.z))) {
return 1;
}var d = Math.min(JS.UnitCell.approx00(v.x) ? 10000 : Math.abs(v.x), JS.UnitCell.approx00(v.y) ? 10000 : Math.min(Math.abs(v.y), JS.UnitCell.approx00(v.z) ? 10000 : Math.abs(v.z)));
if (JS.UnitCell.approx01(v.x / d) && JS.UnitCell.approx01(v.y / d) && JS.UnitCell.approx01(v.z / d)) {
return 1 / d;
}return 1000;
}, "JU.V3");
c$.approx01 = Clazz.defineMethod(c$, "approx01", 
function(f){
f = f % 1;
return (JS.UnitCell.approx00(f) || Math.abs(f) > 0.999);
}, "~N");
c$.approx00 = Clazz.defineMethod(c$, "approx00", 
function(f){
return (Math.abs(f) < 0.001);
}, "~N");
c$.round000 = Clazz.defineMethod(c$, "round000", 
function(y){
y = Math.round(y * 1000) / 1000;
return (y == Math.round(y) ? "" + Math.round(y) : "" + y);
}, "~N");
Clazz.defineMethod(c$, "setMoreInfo", 
function(info){
this.moreInfo = info;
}, "JU.Lst");
Clazz.defineMethod(c$, "getMoreInfo", 
function(){
return this.moreInfo;
});
c$.setSymmetryMinMax = Clazz.defineMethod(c$, "setSymmetryMinMax", 
function(c, rmin, rmax){
if (rmin.x > c.x) rmin.x = c.x;
if (rmin.y > c.y) rmin.y = c.y;
if (rmin.z > c.z) rmin.z = c.z;
if (rmax.x < c.x) rmax.x = c.x;
if (rmax.y < c.y) rmax.y = c.y;
if (rmax.z < c.z) rmax.z = c.z;
}, "JU.P3,JU.P3,JU.P3");
Clazz.defineMethod(c$, "adjustRangeMinMax", 
function(oabc, packingRange, minXYZ, maxXYZ, rmin, rmax, newMin, newMax){
if (rmin == null) {
rmin = JU.P3.new3(3.4028235E38, 3.4028235E38, 3.4028235E38);
rmax = JU.P3.new3(-3.4028235E38, -3.4028235E38, -3.4028235E38);
}var pa =  new JU.P3();
var pb =  new JU.P3();
var pc =  new JU.P3();
if (!Float.isNaN(packingRange)) {
pa.setT(oabc[1]);
pb.setT(oabc[2]);
pc.setT(oabc[3]);
pa.scale(packingRange);
pb.scale(packingRange);
pc.scale(packingRange);
}if (minXYZ != null) {
oabc[0].scaleAdd2(minXYZ.x, oabc[1], oabc[0]);
oabc[0].scaleAdd2(minXYZ.y, oabc[2], oabc[0]);
oabc[0].scaleAdd2(minXYZ.z, oabc[3], oabc[0]);
}oabc[0].sub(pa);
oabc[0].sub(pb);
oabc[0].sub(pc);
var pt = JU.P3.newP(oabc[0]);
this.toFractional(pt, true);
JS.UnitCell.setSymmetryMinMax(pt, rmin, rmax);
if (minXYZ != null) {
oabc[1].scale(maxXYZ.x - minXYZ.x);
oabc[2].scale(maxXYZ.y - minXYZ.y);
oabc[3].scale(maxXYZ.z - minXYZ.z);
}oabc[1].scaleAdd2(2, pa, oabc[1]);
oabc[2].scaleAdd2(2, pb, oabc[2]);
oabc[3].scaleAdd2(2, pc, oabc[3]);
for (var i = 0; i < 3; i++) {
for (var j = i + 1; j < 4; j++) {
pt.add2(oabc[i], oabc[j]);
if (i != 0) pt.add(oabc[0]);
this.toFractional(pt, false);
JS.UnitCell.setSymmetryMinMax(pt, rmin, rmax);
}
}
this.toCartesian(pt, false);
pt.add(oabc[1]);
this.toFractional(pt, false);
JS.UnitCell.setSymmetryMinMax(pt, rmin, rmax);
newMin.set(Clazz.doubleToInt(Math.min(0, Math.floor(rmin.x + 0.001))), Clazz.doubleToInt(Math.min(0, Math.floor(rmin.y + 0.001))), Clazz.doubleToInt(Math.min(0, Math.floor(rmin.z + 0.001))));
newMax.set(Clazz.doubleToInt(Math.max(1, Math.ceil(rmax.x - 0.001))), Clazz.doubleToInt(Math.max(1, Math.ceil(rmax.y - 0.001))), Clazz.doubleToInt(Math.max(1, Math.ceil(rmax.z - 0.001))));
}, "~A,~N,JU.P3i,JU.P3i,JU.P3,JU.P3,JU.P3i,JU.P3i");
Clazz.defineMethod(c$, "toFractionalSpin", 
function(v){
if (this.spinFrameToCartXYZ == null) {
this.toFractional(v, true);
return;
}if (this.spinFrameFromCartXYZ == null) {
this.spinFrameFromCartXYZ = JU.M3.newM3(this.spinFrameToCartXYZ);
this.spinFrameFromCartXYZ.invert();
}this.spinFrameFromCartXYZ.rotate(v);
}, "JU.T3");
Clazz.defineMethod(c$, "toCartesianSpin", 
function(v){
if (this.spinFrameFromCartXYZ == null) {
this.toCartesian(v, true);
return;
}this.spinFrameToCartXYZ.rotate(v);
}, "JU.T3");
Clazz.defineMethod(c$, "getQuaternionRotationForHKL", 
function(uvw, planeHKL){
var c =  new JU.P3();
this.toCartesian(c, false);
var m =  new JU.V3();
var a1 =  new JU.V3();
JU.Measure.getPlaneProjection(c, planeHKL, a1, m);
var type = 0;
if (uvw == null) {
uvw = JU.P3.new3(1, 1, 1);
}if (uvw.x != 0) type |= 4;
if (uvw.y != 0) type |= 2;
if (uvw.z != 0) type |= 1;
var ref =  new JU.V3();
switch (type) {
case 1:
ref.x = -1;
break;
case 2:
ref.z = -1;
break;
case 4:
ref.y = -1;
break;
case 3:
ref.x = 1;
break;
case 5:
ref.y = 1;
break;
case 6:
ref.z = 1;
break;
case 7:
ref.x = 1;
break;
}
a1 =  new JU.V3();
var a2 =  new JU.V3();
a1.cross(m, ref);
a2.cross(a1, m);
a1.cross(a2, m);
a2.normalize();
a1.normalize();
var q = JU.Quat.getQuaternionFrame(null, a1, a2);
return q;
}, "JU.T3,JU.P4");
c$.unitVectors =  Clazz.newArray(-1, [JV.JC.axisX, JV.JC.axisY, JV.JC.axisZ]);
c$.v0 = null;
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
