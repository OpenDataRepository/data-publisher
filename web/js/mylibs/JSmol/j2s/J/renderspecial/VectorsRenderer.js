Clazz.declarePackage("J.renderspecial");
Clazz.load(["J.render.ShapeRenderer", "JU.P3", "$.V3", "JU.Point3fi"], "J.renderspecial.VectorsRenderer", ["J.shape.Shape", "JU.C"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.ptTemp = null;
this.pointVectorStart = null;
this.pointVectorEnd = null;
this.pointArrowHead = null;
this.screenVectorStart = null;
this.screenVectorEnd = null;
this.screenArrowHead = null;
this.headOffsetVector = null;
this.pTemp3 = null;
this.diameter = 0;
this.headWidthPixels = 0;
this.vectorScale = 0;
this.vectorSymmetry = false;
this.headScale = 0;
this.drawShaft = false;
this.vectorsCentered = false;
this.standardVector = true;
this.vibrationOn = false;
this.drawCap = false;
this.showModVecs = false;
this.vectorTrail = 0;
this.ptTemp4 = null;
this.ptTemp2 = null;
this.displayVectorHalo = false;
this.isDisplayHaloPass1 = false;
Clazz.instantialize(this, arguments);}, J.renderspecial, "VectorsRenderer", J.render.ShapeRenderer);
Clazz.prepareFields (c$, function(){
this.ptTemp =  new JU.Point3fi();
this.pointVectorStart =  new JU.P3();
this.pointVectorEnd =  new JU.Point3fi();
this.pointArrowHead =  new JU.P3();
this.screenVectorStart =  new JU.P3();
this.screenVectorEnd =  new JU.P3();
this.screenArrowHead =  new JU.P3();
this.headOffsetVector =  new JU.V3();
this.pTemp3 =  new JU.P3();
});
Clazz.overrideMethod(c$, "render", 
function(){
var vectors = this.shape;
if (!vectors.isActive) return false;
var mads = vectors.mads;
if (mads == null) return false;
var colixes = vectors.colixes;
this.vectorScale = this.vwr.getFloat(1648361473);
this.vectorTrail = this.vwr.getInt(553648185);
var atoms = this.ms.at;
this.displayVectorHalo = (this.vwr.getSelectionHalosEnabled());
var bsSelected = (this.displayVectorHalo ? this.vwr.bsA() : null);
var needTranslucent = this.displayVectorHalo;
this.isDisplayHaloPass1 =  new Boolean (this.displayVectorHalo & !this.vwr.gdata.isPass2).valueOf();
this.displayVectorHalo = new Boolean (this.displayVectorHalo & this.vwr.gdata.isPass2).valueOf();
this.colix = (this.displayVectorHalo ? JU.C.getColixTranslucent3(23, true, 0.5) : -1);
if (this.vectorScale < 0) {
var maxScale = 0;
for (var i = this.ms.ac; --i >= 0; ) {
var vib = this.ms.getVibration(i, false);
if (vib != null && vib.magMoment > 0) {
var d = vib.length();
if (d > maxScale) {
maxScale = d;
}}}
if (maxScale > 0) {
this.vectorScale /= -maxScale;
} else {
this.vectorScale = 1;
}}this.vectorSymmetry = this.vwr.getBoolean(603979973);
this.vectorsCentered = this.vwr.getBoolean(603979972);
this.showModVecs = this.vwr.getBoolean(603979927);
this.vibrationOn = this.vwr.tm.vibrationOn;
this.headScale = -0.2;
var haveModulations = false;
var isSelected = false;
for (var i = this.ms.ac; --i >= 0; ) {
var atom = atoms[i];
if (!this.isVisibleForMe(atom)) continue;
var mod = this.ms.getModulation(i);
if (this.showModVecs && !haveModulations && mod != null) haveModulations = true;
var vib = this.ms.getVibration(i, false);
if (vib == null) continue;
isSelected = (bsSelected != null && bsSelected.get(i));
if (!this.transform(mads[i], atom, vib, mod, isSelected)) continue;
if (!this.g3d.setC(this.colix == -1 || !isSelected ? J.shape.Shape.getColix(colixes, i, atom) : this.colix)) {
needTranslucent = true;
continue;
}this.renderVector(atom, vib);
if (this.vectorSymmetry) {
this.vectorScale = -this.vectorScale;
this.headScale = -this.headScale;
this.transform(mads[i], atom, vib, null, false);
this.renderVector(atom, vib);
this.vectorScale = -this.vectorScale;
this.headScale = -this.headScale;
}}
if (haveModulations) {
for (var i = this.ms.ac; --i >= 0; ) {
var atom = atoms[i];
if (!this.isVisibleForMe(atom)) continue;
var mod = this.ms.getModulation(i);
if (mod == null) continue;
if (!this.g3d.setC(J.shape.Shape.getColix(colixes, i, atom))) {
needTranslucent = true;
continue;
}if (!this.transform(mads[i], atom, null, mod, isSelected)) continue;
this.renderVector(atom, null);
}
}return needTranslucent;
});
Clazz.defineMethod(c$, "transform", 
function(mad, atom, vib, mod2, isSelected){
var isMod = (vib == null || vib.modDim >= 0);
var isSpin = (!isMod && vib.modDim == -2);
if (vib == null) vib = mod2;
var isHighlight = isSelected && this.displayVectorHalo;
if (!isMod) {
var len = vib.length();
if (Math.abs(len * this.vectorScale) < 0.01) return false;
this.standardVector = true;
this.drawShaft = !isHighlight && (0.1 + Math.abs(this.headScale / len) < Math.abs(this.vectorScale));
this.headOffsetVector.setT(vib.isFrom000 ? atom : vib);
this.headOffsetVector.scale(this.headScale / len);
}this.ptTemp.setT(atom);
var mod = atom.getModulation();
if (this.vibrationOn && mod != null) this.vwr.tm.getVibrationPoint(mod, this.ptTemp, 1);
if (isMod) {
this.standardVector = false;
this.drawShaft = true;
mod = vib;
this.pointVectorStart.setT(this.ptTemp);
this.pointVectorEnd.setT(this.ptTemp);
if (mod.isEnabled()) {
if (this.vibrationOn) {
this.vwr.tm.getVibrationPoint(vib, this.pointVectorEnd, NaN);
}mod.addTo(this.pointVectorStart, NaN);
} else {
mod.addTo(this.pointVectorEnd, 1);
}this.headOffsetVector.sub2(this.pointVectorEnd, this.pointVectorStart);
var len = this.headOffsetVector.length();
this.drawCap = (len + -0.2 > 0.001);
this.drawShaft = (len > 0.01);
this.headOffsetVector.scale(this.headScale / this.headOffsetVector.length());
} else {
this.drawCap = !isSelected || !this.isDisplayHaloPass1;
if (this.vectorsCentered || isSpin) {
this.standardVector = false;
this.pointVectorEnd.scaleAdd2(0.5 * this.vectorScale, vib, this.ptTemp);
if (this.vectorSymmetry) {
this.pointVectorStart.setP(this.ptTemp);
} else {
this.pointVectorStart.scaleAdd2(-0.5 * this.vectorScale, vib, this.ptTemp);
}} else {
if (vib.isFrom000) {
this.pointVectorStart.set(0, 0, 0);
this.tm.transformPtScrT3(this.pointVectorStart, this.screenVectorStart);
this.pointVectorEnd.setP(atom);
} else {
this.pointVectorEnd.scaleAdd2(this.vectorScale, vib, this.ptTemp);
}this.pointArrowHead.add2(this.pointVectorEnd, this.headOffsetVector);
if (this.vibrationOn) {
var screen = this.tm.transformPtVib(this.pointVectorEnd, vib);
this.screenVectorEnd.set(screen.x, screen.y, screen.z);
screen = this.tm.transformPtVib(this.pointArrowHead, vib);
this.screenArrowHead.set(screen.x, screen.y, screen.z);
} else {
this.tm.transformPtScrT3(this.pointVectorEnd, this.screenVectorEnd);
this.tm.transformPtScrT3(this.pointArrowHead, this.screenArrowHead);
}}}if (!this.standardVector) {
this.tm.transformPtScrT3(this.pointVectorEnd, this.screenVectorEnd);
this.tm.transformPtScrT3(this.pointVectorStart, this.screenVectorStart);
if (this.drawCap || this.isDisplayHaloPass1) this.pointArrowHead.add2(this.pointVectorEnd, this.headOffsetVector);
 else this.pointArrowHead.setT(this.pointVectorEnd);
this.tm.transformPtScrT3(this.pointArrowHead, this.screenArrowHead);
}this.diameter = Clazz.floatToInt(mad < 0 ? -mad : mad < 1 ? 1 : this.vwr.tm.scaleToScreen(Clazz.floatToInt(this.screenVectorEnd.z), mad));
this.headWidthPixels = this.diameter << 1;
if (this.headWidthPixels < this.diameter + 2) this.headWidthPixels = this.diameter + 2;
return true;
}, "~N,JM.Atom,JU.Vibration,J.api.JmolModulationSet,~B");
Clazz.defineMethod(c$, "renderVector", 
function(atom, vib){
if (vib != null && this.vectorTrail > 0) {
if (this.ptTemp4 == null) {
this.ptTemp4 =  new JU.P3();
this.ptTemp2 =  new JU.P3();
}var d = Math.max(1, this.diameter >> 2);
var pts = vib.addTracePt(this.vectorTrail, this.vibrationOn ? this.pointVectorEnd : null);
this.tm.transformPtScrT3(atom, this.ptTemp4);
if (pts != null) for (var i = pts.length, p = this.vectorTrail; --i >= 0; ) {
var pt = pts[--p];
if (pt == null) break;
this.tm.transformPtScrT3(pt, this.ptTemp2);
this.g3d.fillCylinderBits(2, d, this.ptTemp4, this.ptTemp2);
}
}if (this.drawShaft) {
this.pTemp3.set(atom.sX, atom.sY, atom.sZ);
if (this.standardVector && !vib.isFrom000) this.g3d.fillCylinderBits(2, this.diameter, this.pTemp3, this.screenArrowHead);
 else this.g3d.fillCylinderBits(2, this.diameter, this.screenVectorStart, this.screenArrowHead);
}if (this.drawCap) this.g3d.fillConeScreen3f(2, this.headWidthPixels, this.screenArrowHead, this.screenVectorEnd, false);
}, "JM.Atom,JU.Vibration");
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
