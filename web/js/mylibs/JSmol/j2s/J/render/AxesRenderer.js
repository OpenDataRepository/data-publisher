Clazz.declarePackage("J.render");
Clazz.load(["J.render.CageRenderer", "JU.P3"], "J.render.AxesRenderer", ["JV.JC"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.originScreen = null;
this.colixes = null;
this.pt000 = null;
this.modelIndex = 0;
this.isDataSpin = false;
this.ptTemp = null;
Clazz.instantialize(this, arguments);}, J.render, "AxesRenderer", J.render.CageRenderer);
Clazz.prepareFields (c$, function(){
this.originScreen =  new JU.P3();
this.colixes =  Clazz.newShortArray (3, 0);
this.ptTemp =  new JU.P3();
});
Clazz.overrideMethod(c$, "initRenderer", 
function(){
this.endcap = 2;
this.draw000 = false;
});
Clazz.overrideMethod(c$, "render", 
function(){
if (this.vwr.ms !== this.ms) return false;
var axes = this.shape;
var mad10 = this.vwr.getObjectMad10(1);
var isXY = (axes.axisXY.z != 0);
if (mad10 == 0 || !this.g3d.checkTranslucent(false)) return false;
if (isXY ? this.exportType == 1 : this.tm.isNavigating() && this.vwr.getBoolean(603979890)) return false;
this.modelIndex = this.vwr.am.cmi;
var m = null;
this.isDataSpin = false;
var isUnitCell = (this.vwr.g.axesMode == 603979808);
if (this.ms.isJmolDataFrame(this.modelIndex)) {
switch (this.ms.getJmolFrameTypeInt(this.modelIndex)) {
case 1095241729:
m = this.ms.am[this.modelIndex];
this.isDataSpin = (m.uvw != null);
isUnitCell = true;
break;
case 134221834:
break;
default:
return false;
}
}var unitcell = (isUnitCell ? this.vwr.getCurrentUnitCell() : null);
if (!this.isDataSpin && isUnitCell && (unitcell == null || this.modelIndex < 0)) return false;
this.imageFontScaling = this.vwr.imageFontScaling;
if (!this.isDataSpin && this.vwr.areAxesTainted()) axes.reinitShape();
this.font3d = this.vwr.gdata.getFont3DScaled(axes.font3d, this.imageFontScaling);
isUnitCell = isUnitCell && (unitcell != null);
var axisType = (isUnitCell ? axes.axisType : null);
var isabcxyz = (isXY && isUnitCell && axes.axes2 != null);
this.isPolymer = isUnitCell && unitcell.isPolymer();
this.isSlab = isUnitCell && unitcell.isSlab();
this.nDims = (isUnitCell ? unitcell.getDimensionality() : 3);
this.periodicity = (isUnitCell ? unitcell.getPeriodicity() : 0x7);
this.setShifts();
var scale = axes.scale;
if (isabcxyz) {
if ("xyzabc".equals(axes.axes2)) this.render1(axes, mad10, false, axisType, isUnitCell, 2, null, null);
if (!"abc".equals(axes.axes2)) this.vwr.setBooleanProperty("axesmolecular", true);
axes.reinitShape();
this.render1(axes, mad10, true, null, false, scale, axes.axes2, null);
this.vwr.setBooleanProperty("axesunitcell", true);
} else if (this.isDataSpin) {
this.pt0.set(0, 0, 0);
this.render1(null, 25000, false, null, true, 1, null, m.uvw);
} else {
this.render1(axes, mad10, isXY, axisType, isUnitCell, scale, null, null);
}return true;
});
Clazz.defineMethod(c$, "render1", 
function(axes, mad10, isXY, axisType, isUnitCell, scale, labels2, pts){
var isDataFrame = this.vwr.isJmolDataFrame();
this.pt000 = (isDataFrame ? this.pt0 : axes.originPoint);
var nPoints = 6;
var labelPtr = 0;
if (isUnitCell) {
nPoints = 3;
labelPtr = (this.isDataSpin ? 21 : 6);
} else if (isXY) {
nPoints = 3;
labelPtr = 9;
} else if (this.vwr.g.axesMode == 603979809) {
nPoints = 6;
labelPtr = (this.vwr.getBoolean(603979806) ? 15 : 9);
}if (axes != null && axes.labels != null) {
if (nPoints != 3) nPoints = (axes.labels.length < 6 ? 3 : 6);
labelPtr = -1;
}var slab = this.vwr.gdata.slab;
var diameter = mad10;
var drawTicks = false;
this.ptTemp.setT(this.originScreen);
var checkAxisType = (labels2 == null && axisType != null && (isXY || this.vwr.getFloat(570425345) != 0 || axes.fixedOrigin != null));
if (isXY) {
if (mad10 >= 20) {
diameter = (mad10 > 500 ? 3 : Clazz.doubleToInt(mad10 / 200));
if (diameter == 0) diameter = 2;
}if (this.g3d.isAntialiased()) diameter += diameter;
this.g3d.setSlab(0);
this.ptTemp.setT(axes.axisXY);
this.pt0i.setT(this.tm.transformPt2D(this.ptTemp));
if (this.ptTemp.x < 0) {
var offx = Clazz.floatToInt(this.ptTemp.x);
var offy = Clazz.floatToInt(this.ptTemp.x);
this.pointT.setT(this.pt000);
for (var i = 0; i < 3; i++) this.pointT.add(axes.getAxisPoint(i, false, this.ptTemp));

this.pt0i.setT(this.tm.transformPt(this.pt000));
this.pt2i.scaleAdd(-1, this.pt0i, this.tm.transformPt(this.pointT));
if (this.pt2i.x < 0) offx = -offx;
if (this.pt2i.y < 0) offy = -offy;
this.pt0i.x += offx;
this.pt0i.y += offy;
}this.ptTemp.set(this.pt0i.x, this.pt0i.y, this.pt0i.z);
var zoomDimension = this.vwr.getScreenDim();
var scaleFactor = zoomDimension / 10 * scale;
if (this.g3d.isAntialiased()) scaleFactor *= 2;
if ((isUnitCell || "abc".equals(axes.axes2)) && isXY) scaleFactor /= 2;
for (var i = 0; i < 3; i++) {
var pt = this.p3Screens[i];
this.tm.rotatePoint(axes.getAxisPoint(i, false, this.pointT), pt);
pt.z *= -1;
pt.scaleAdd2(scaleFactor, pt, this.ptTemp);
}
} else {
drawTicks = (axes != null && axes.tickInfos != null);
if (drawTicks) {
this.checkTickTemps();
this.tickA.setT(this.pt000);
}this.tm.transformPtNoClip(this.pt000, this.ptTemp);
diameter = this.getDiameter(Clazz.floatToInt(this.ptTemp.z), mad10);
for (var i = nPoints; --i >= 0; ) {
var p = (pts == null ? axes.getAxisPoint(i, !isDataFrame, this.pointT) : pts[i]);
this.tm.transformPtNoClip(p, this.p3Screens[i]);
}
if (this.shifting) {
if (this.vvert == null) {
this.vvert =  Clazz.newArray(-1, [ new JU.P3(),  new JU.P3(),  new JU.P3(),  new JU.P3(),  new JU.P3(),  new JU.P3()]);
}if (this.shiftA) {
this.setVVert(axes, 0);
}if (this.shiftB) {
this.setVVert(axes, 1);
}if (this.shiftC) {
this.setVVert(axes, 2);
}}}var xCenter = this.ptTemp.x;
var yCenter = this.ptTemp.y;
this.colixes[0] = this.vwr.getObjectColix(1);
this.colixes[1] = this.vwr.getObjectColix(2);
this.colixes[2] = this.vwr.getObjectColix(3);
var showOrigin = (this.nDims == 3 && !isXY && nPoints == 3 && (scale == 2 || isUnitCell && !this.isDataSpin));
if (this.nDims == 2) nPoints = 2;
for (var i = nPoints; --i >= 0; ) {
if (labels2 != null && i >= labels2.length || checkAxisType && !axisType.contains(JV.JC.axesTypes[i]) || this.exportType != 1 && (Math.abs(xCenter - this.p3Screens[i].x) + Math.abs(yCenter - this.p3Screens[i].y) <= 2) && (!(showOrigin = false))) {
continue;
}this.colix = this.colixes[i % 3];
this.g3d.setC(this.colix);
var label = (labels2 != null ? labels2.substring(i, i + 1) : axes == null || axes.labels == null ? JV.JC.axisLabels[i + labelPtr] : i < axes.labels.length ? axes.labels[i] : null);
if (label != null && label.length > 0) {
var p = (i == 0 && this.shiftA ? this.vvert[1] : i == 1 && this.shiftB ? this.vvert[3] : i == 2 && this.shiftC ? this.vvert[5] : this.p3Screens[i]);
this.renderLabel(label, p.x, p.y, p.z, xCenter, yCenter);
}if (drawTicks) {
this.tickInfo = axes.tickInfos[(i % 3) + 1];
if (this.tickInfo == null) this.tickInfo = axes.tickInfos[0];
if (this.tickInfo != null) {
this.tickB.setT(axes.getAxisPoint(i, isDataFrame || isUnitCell, this.pointT));
this.tickInfo.first = 0;
this.tickInfo.signFactor = (i % 6 >= 3 ? -1 : 1);
}}var d = (this.isSlab && i == 2 || this.isPolymer && i > 0 ? -4 : diameter);
var p1 = this.ptTemp;
var p2 = this.p3Screens[i];
if (i == 2 && this.shiftC) {
p1 = this.vvert[4];
p2 = this.vvert[5];
} else if (i == 1 && this.shiftB) {
p1 = this.vvert[2];
p2 = this.vvert[3];
} else if (i == 0 && this.shiftA) {
p1 = this.vvert[0];
p2 = this.vvert[1];
}this.renderLine(p1, p2, d, drawTicks && this.tickInfo != null);
}
if (showOrigin) {
var label0 = (axes.labels == null || axes.labels.length == 3 || axes.labels[3] == null ? "0" : axes.labels[3]);
if (label0 != null && label0.length != 0) {
this.colix = this.vwr.cm.colixBackgroundContrast;
this.g3d.setC(this.colix);
this.renderLabel(label0, xCenter, yCenter, this.ptTemp.z, xCenter, yCenter);
}}if (isXY) this.g3d.setSlab(slab);
}, "J.shape.Axes,~N,~B,~S,~B,~N,~S,~A");
Clazz.defineMethod(c$, "setVVert", 
function(axes, i){
var vpt = i * 2;
this.vvert[vpt].setT(axes.originPoint);
axes.getAxisPoint(i, false, this.pt);
this.vvert[vpt].sub(this.pt);
this.pt.add(axes.originPoint);
this.tm.transformPtNoClip(this.vvert[vpt], this.vvert[vpt]);
this.tm.transformPtNoClip(this.pt, this.vvert[vpt + 1]);
}, "J.shape.Axes,~N");
Clazz.defineMethod(c$, "renderLabel", 
function(str, x, y, z, xCenter, yCenter){
var strAscent = this.font3d.getAscent();
var strWidth = this.font3d.stringWidth(str);
var dx = x - xCenter;
var dy = y - yCenter;
if ((dx != 0 || dy != 0)) {
var dist = Math.sqrt(dx * dx + dy * dy);
dx = (strWidth * 0.75 * dx / dist);
dy = (strAscent * 0.75 * dy / dist);
x += dx;
y += dy;
}var xStrBaseline = Math.floor(x - strWidth / 2);
var yStrBaseline = Math.floor(y + strAscent / 2);
this.g3d.drawString(str, this.font3d, Clazz.doubleToInt(xStrBaseline), Clazz.doubleToInt(yStrBaseline), Clazz.floatToInt(z), Clazz.floatToInt(z), 0);
}, "~S,~N,~N,~N,~N,~N");
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
