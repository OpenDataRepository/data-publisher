Clazz.declarePackage("JU");
Clazz.load(["JU.V3", "$.M3"], "JU.Vibration", ["JU.P3", "$.Quat", "JU.BSUtil"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.modDim = -1;
this.modScale = NaN;
this.magMoment = 0;
this.showTrace = false;
this.isFractional = false;
this.v0 = null;
this.tracePt = 0;
this.trace = null;
this.symmform = null;
this.spin = 0;
this.isFrom000 = false;
this.matTemp = null;
this.matInv = null;
Clazz.instantialize(this, arguments);}, JU, "Vibration", JU.V3);
Clazz.prepareFields (c$, function(){
this.matTemp =  new JU.M3();
this.matInv =  new JU.M3();
});
Clazz.defineMethod(c$, "setCalcPoint", 
function(pt, t456, scale, modulationScale){
switch (this.modDim) {
case -2:
case -3:
break;
default:
pt.scaleAdd2((Math.cos(t456.x * 6.283185307179586) * scale), this, pt);
break;
}
return pt;
}, "JU.T3,JU.T3,~N,~N");
Clazz.defineMethod(c$, "getInfo", 
function(info){
info.put("vibVector", JU.V3.newV(this));
info.put("vibType", (this.modDim == -2 ? "spin" : this.modDim == -1 ? "vib" : "mod"));
}, "java.util.Map");
Clazz.overrideMethod(c$, "clone", 
function(){
var v =  new JU.Vibration();
v.setT(this);
v.modDim = this.modDim;
v.magMoment = this.magMoment;
v.v0 = this.v0;
return v;
});
Clazz.defineMethod(c$, "setXYZ", 
function(vib){
this.setT(vib);
}, "JU.T3");
Clazz.defineMethod(c$, "setV0", 
function(){
this.v0 = JU.V3.newV(this);
});
Clazz.defineMethod(c$, "setType", 
function(type){
this.modDim = type;
return this;
}, "~N");
Clazz.defineMethod(c$, "isNonzero", 
function(){
return this.x != 0 || this.y != 0 || this.z != 0;
});
Clazz.defineMethod(c$, "getOccupancy100", 
function(isTemp){
return -2147483648;
}, "~B");
Clazz.defineMethod(c$, "startTrace", 
function(n){
this.trace =  new Array(n);
this.tracePt = n;
}, "~N");
Clazz.defineMethod(c$, "addTracePt", 
function(n, ptNew){
if (this.trace == null || n == 0 || n != this.trace.length) this.startTrace(n);
if (ptNew != null && n > 2) {
if (--this.tracePt <= 0) {
var p0 = this.trace[this.trace.length - 1];
for (var i = this.trace.length; --i >= 1; ) this.trace[i] = this.trace[i - 1];

this.trace[1] = p0;
this.tracePt = 1;
}var p = this.trace[this.tracePt];
if (p == null) p = this.trace[this.tracePt] =  new JU.P3();
p.setT(ptNew);
}return this.trace;
}, "~N,JU.Point3fi");
Clazz.defineMethod(c$, "getApproxString100", 
function(){
return Math.round(this.x * 100) + "," + Math.round(this.y * 100) + "," + Math.round(this.z * 100);
});
Clazz.defineMethod(c$, "rotateSpin", 
function(matInv, rot, dRot, a){
JU.Vibration.rot(matInv, rot, dRot, this);
if (this.isFrom000) {
JU.Vibration.rot(matInv, rot, dRot, a);
}}, "JU.M3,JU.M3,JU.M3,JM.Atom");
c$.rot = Clazz.defineMethod(c$, "rot", 
function(matInv, rot, dRot, t){
if (matInv == null) {
dRot.rotate(t);
} else {
matInv.rotate(t);
rot.rotate(t);
}}, "JU.M3,JU.M3,JU.M3,JU.T3");
Clazz.defineMethod(c$, "rotateModelSpinVectors", 
function(ms, modelIndex, rot, isdx){
if (modelIndex < 0 || modelIndex >= ms.mc || ms.vibrations == null) return -1;
var vwr = ms.vwr;
var m = ms.am[modelIndex];
if (m.isJmolDataFrame) modelIndex = m.dataSourceFrame;
var info = ms.getModelAuxiliaryInfo(modelIndex);
if (rot == null) {
rot = info.get("spinFrameRotationMatrix");
if (rot == null) return -1;
}var noref = Double.isNaN(rot.getElement(0, 0));
var isScreenZ = (noref && rot.getElement(2, 2) == 1);
var deg = (noref || isScreenZ ? rot.getElement(1, 1) : 0);
if (noref && deg != 0) {
rot.setElement(1, 1, 0);
this.rotateModelSpinVectors(ms, modelIndex, rot, false);
rot.setElement(1, 1, deg);
}var m0 = info.get("spinFrameRotationMatrix");
var mat = info.get("spinRotationMatrixApplied");
if (mat == null && m0 == null) {
m0 = JU.M3.newM3(null);
info.put("spinFrameRotationMatrix", m0);
}if (mat == null) {
mat = m0;
}if (noref) {
var qn;
if (isScreenZ) {
var pt3 = JU.P3.new3(0, 0, 100);
var pt4 = JU.P3.new3(0, 0, 200);
vwr.tm.unTransformPoint(pt3, pt3);
vwr.tm.unTransformPoint(pt4, pt4);
qn = JU.V3.newVsub(pt3, pt4);
} else {
qn = JU.Quat.newM(mat).getNormal();
}rot = JU.Quat.newVA(qn, deg).getMatrix();
}var bs = JU.BSUtil.newAndSetBit(modelIndex);
ms.includeAllRelatedFrames(bs, true);
var bsModels = JU.BSUtil.copy(bs);
bs = vwr.getModelUndeletedAtomsBitSetBs(bs);
var matInv;
var drot;
drot = this.matTemp;
if (isdx) {
drot.setM3(rot);
rot.mul2(rot, mat);
matInv = null;
} else {
matInv = this.matInv;
matInv.setM3(mat);
matInv.invert();
}for (var i = bs.nextSetBit(0); i >= 0; i = bs.nextSetBit(i + 1)) {
if (i >= ms.vibrations.length) break;
var v = ms.vibrations[i];
if (v == null || !v.isFrom000 && v.magMoment == 0) continue;
v.rotateSpin(matInv, rot, drot, ms.at[i]);
}
var quat = JU.Quat.newM(rot);
var aa = quat.toAxisAngle4f();
mat = JU.M3.newM3(rot);
info.put("spinRotationMatrixApplied", mat);
var jmolSpinModel = -1;
for (var i = bsModels.nextSetBit(0); i >= 0; i = bsModels.nextSetBit(i + 1)) {
m = ms.am[i];
if (m.uvw0 != null) {
jmolSpinModel = i;
mat.rotate2(m.uvw0[0], m.uvw[0]);
mat.rotate2(m.uvw0[1], m.uvw[1]);
mat.rotate2(m.uvw0[2], m.uvw[2]);
}}
info.put("spinRotationAxisAngleApplied", aa);
if (ms.unitCells[modelIndex] != null) ms.unitCells[modelIndex].setSpinAxisAngle(aa);
return jmolSpinModel;
}, "JM.ModelSet,~N,JU.M3,~B");
c$.find = Clazz.defineMethod(c$, "find", 
function(modelSet, bs, v0){
if (v0 != null) for (var i = bs.nextSetBit(0); i >= 0; i = bs.nextSetBit(i + 1)) {
var v = modelSet.getVibration(i, false);
if (v != null && v.distance(v0) < 0.1) return modelSet.at[i];
}
return null;
}, "JM.ModelSet,JU.BS,JU.Vibration");
Clazz.defineMethod(c$, "resetMoment", 
function(){
if (this.magMoment == 0) return;
var d = this.lengthSquared();
if (d != 0) this.scale(this.magMoment / Math.sqrt(d));
});
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
