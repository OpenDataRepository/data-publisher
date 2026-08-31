Clazz.declarePackage("J.rendercgo");
Clazz.load(["J.renderspecial.DrawRenderer", "JU.P3"], "J.rendercgo.CGORenderer", ["JU.Lst", "J.shapecgo.CGOMesh", "JU.C", "$.Logger"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.cgoMesh = null;
this.cmds = null;
this.pt3 = null;
this.colix0 = 0;
this.colix1 = 0;
this.colix2 = 0;
this.normix0 = 0;
this.normix1 = 0;
this.normix2 = 0;
this.normix = 0;
this.doColor = false;
this.ptNormal = 0;
this.ptColor = 0;
this.map0 = null;
this.vX = null;
this.vY = null;
this.x0 = 0;
this.y0 = 0;
this.dx = 0;
this.dy = 0;
this.scaleX = 0;
this.scaleY = 0;
this.is2D = false;
this.is2DPercent = false;
this.isMapped = false;
this.isPS = false;
this.screenZ = 0;
this.alpha = 0;
this.nPts = 0;
this.glMode = 0;
this.needVertices = false;
this.vertexList = null;
this.pt = null;
this.spt = null;
this.lastPoint = null;
Clazz.instantialize(this, arguments);}, J.rendercgo, "CGORenderer", J.renderspecial.DrawRenderer);
Clazz.prepareFields (c$, function(){
this.pt3 =  new JU.P3();
});
Clazz.overrideMethod(c$, "render", 
function(){
this.needTranslucent = false;
this.imageFontScaling = this.vwr.imageFontScaling;
var cgo = this.shape;
for (var i = cgo.meshCount; --i >= 0; ) this.render2(this.mesh = this.cgoMesh = cgo.meshes[i]);

return this.needTranslucent;
});
Clazz.defineMethod(c$, "render2", 
function(mesh){
this.needVertices = (mesh.vs == null && this.vwr.getDrawHover());
if (this.needVertices) this.vertexList =  new JU.Lst();
this.diameter = this.cgoMesh.diameter;
this.width = this.cgoMesh.width;
this.cmds = this.cgoMesh.cmds;
if (this.cmds == null || !this.cgoMesh.visible || this.cgoMesh.visibilityFlags == 0) return;
if (!this.g3d.setC(this.cgoMesh.colix)) {
this.needTranslucent = true;
return;
}var n = this.cmds.size();
this.glMode = -1;
this.nPts = 0;
this.ptNormal = 0;
this.ptColor = 0;
this.screenZ = 2147483647;
this.doColor = !mesh.useColix;
this.g3d.addRenderer(1073742182);
this.is2D = this.isMapped = false;
this.scaleX = this.scaleY = 1;
for (var j = 0; j < n; j++) {
var type = this.cgoMesh.getInt(j);
if (type == 0) break;
var len = J.shapecgo.CGOMesh.getSize(type, this.is2D);
if (len < 0) {
JU.Logger.error("CGO unknown type: " + type);
return;
}switch (type) {
default:
System.out.println("CGO ? " + type);
break;
case 36:
break;
case 28:
len = this.drawArray(j + 1);
break;
case -111:
break;
case -107:
this.diameter = this.cgoMesh.getInt(j + 1);
break;
case -100:
this.width = this.cgoMesh.getFloat(j + 1);
break;
case -101:
this.isMapped = false;
var f = this.cgoMesh.getFloat(j + 1);
if (f == 0) {
this.is2D = false;
} else {
this.is2DPercent = (f > 0);
this.screenZ = (this.is2DPercent ? this.tm.zValueFromPercent(Clazz.floatToInt(f)) : -Clazz.floatToInt(f));
this.is2D = true;
}break;
case -103:
this.isPS = true;
case -102:
this.is2D = this.isMapped = true;
this.map0 =  new JU.P3();
this.vX =  new JU.P3();
this.vY =  new JU.P3();
this.cgoMesh.getPoint(j + 1, this.map0);
this.cgoMesh.getPoint(j + 4, this.vX);
this.vX.sub(this.map0);
this.cgoMesh.getPoint(j + 7, this.vY);
this.vY.sub(this.map0);
this.x0 = this.cgoMesh.getFloat(j + 10);
this.y0 = this.cgoMesh.getFloat(j + 11);
this.dx = this.cgoMesh.getFloat(j + 12) - this.x0;
this.dy = this.cgoMesh.getFloat(j + 13) - this.y0;
if (this.isPS) break;
case -108:
this.scaleX = this.cgoMesh.getFloat(this.isPS ? j + 1 : j + 14);
this.scaleY = this.cgoMesh.getFloat(this.isPS ? j + 2 : j + 15);
break;
case 25:
this.alpha = this.cgoMesh.getFloat(j + 1);
break;
case 30:
break;
case 1:
this.getPoint(j + 2, this.pt0, this.pt0i);
this.getPoint(j + (this.is2D ? 4 : 5), this.pt1, this.pt1i);
this.drawEdge(1, 2, false, this.pt0, this.pt1, this.pt0i, this.pt1i);
break;
case 2:
this.glMode = this.cgoMesh.getInt(j + 1);
case -104:
this.nPts = 0;
break;
case -105:
this.glMode = -105;
break;
case -106:
if (this.glMode != -105) break;
this.glMode = 2;
case 3:
if (this.glMode == 2 && this.nPts >= 3) this.drawEdge(1, 2, true, this.pt0, this.pt3, this.pt0i, this.pt3i);
this.nPts = 0;
break;
case 6:
this.getColix(true);
break;
case 5:
this.normix = this.getNormix();
break;
case -109:
this.nPts = 0;
case -110:
this.glMode = 2;
case 4:
this.processVertex(j);
break;
case 14:
this.getPoint(j, this.pt0, this.pt0i);
this.getPoint(j + (this.is2D ? 2 : 3), this.pt1, this.pt1i);
this.width = this.cgoMesh.getFloat(j + 7);
this.getColix(true);
this.getColix(false);
this.drawEdge(1, 2, false, this.pt0, this.pt1, this.pt0i, this.pt1i);
this.width = 0;
break;
case 8:
this.getPoint(j, this.pt0, this.pt0i);
this.getPoint(j + (this.is2D ? 2 : 3), this.pt1, this.pt1i);
this.getPoint(j + (this.is2D ? 4 : 6), this.pt2, this.pt2i);
this.normix0 = this.getNormix();
this.normix1 = this.getNormix();
this.normix2 = this.getNormix();
this.colix0 = this.getColix(false);
this.colix1 = this.getColix(false);
this.colix2 = this.getColix(false);
this.fillTriangle();
break;
}
j += len;
}
if (this.needVertices) {
mesh.vs = this.vertexList.toArray( new Array(this.vertexList.size()));
this.vertexList = null;
}}, "J.shape.Mesh");
Clazz.defineMethod(c$, "processVertex", 
function(j){
if (this.nPts++ == 0) this.getPoint(j, this.pt0, this.pt0i);
switch (this.glMode) {
case -1:
break;
case 36:
case 0:
this.drawEdge(1, 1, false, this.pt0, this.pt0, this.pt0i, this.pt0i);
break;
case 1:
if (this.nPts == 2) {
this.getPoint(j, this.pt1, this.pt1i);
this.drawEdge(1, 2, false, this.pt0, this.pt1, this.pt0i, this.pt1i);
this.nPts = 0;
return;
}break;
case 2:
case 3:
if (this.nPts == 1) {
if (this.glMode == 2) {
this.pt3.setT(this.pt0);
this.pt3i.setT(this.pt0i);
}break;
}this.getPoint(j, this.pt1, this.pt1i);
this.pt = this.pt0;
this.pt0 = this.pt1;
this.pt1 = this.pt;
this.spt = this.pt0i;
this.pt0i = this.pt1i;
this.pt1i = this.spt;
this.drawEdge(1, 2, true, this.pt0, this.pt1, this.pt0i, this.pt1i);
break;
case 4:
switch (this.nPts) {
case 1:
this.normix1 = this.normix2 = this.normix0 = this.normix;
this.colix1 = this.colix2 = this.colix0 = this.colix;
break;
case 2:
this.getPoint(j, this.pt1, this.pt1i);
break;
case 3:
this.getPoint(j, this.pt2, this.pt2i);
this.fillTriangle();
this.nPts = 0;
return;
}
break;
case 5:
switch (this.nPts) {
case 1:
this.normix1 = this.normix2 = this.normix0 = this.normix;
this.colix1 = this.colix2 = this.colix0 = this.colix;
break;
case 2:
this.getPoint(j, this.pt2, this.pt2i);
break;
default:
if (this.nPts % 2 == 0) {
this.pt = this.pt0;
this.pt0 = this.pt2;
this.spt = this.pt0i;
this.pt0i = this.pt2i;
} else {
this.pt = this.pt1;
this.pt1 = this.pt2;
this.spt = this.pt1i;
this.pt1i = this.pt2i;
}this.pt2 = this.pt;
this.pt2i = this.spt;
this.getPoint(j, this.pt2, this.pt2i);
this.fillTriangle();
break;
}
break;
case 6:
switch (this.nPts) {
case 1:
this.normix1 = this.normix2 = this.normix0 = this.normix;
this.colix1 = this.colix2 = this.colix0 = this.colix;
this.pt1.setT(this.pt0);
this.pt1i.setT(this.pt0i);
break;
case 2:
this.getPoint(j, this.pt0, this.pt0i);
break;
default:
this.pt2.setT(this.pt0);
this.pt2i.setT(this.pt0i);
this.getPoint(j, this.pt0, this.pt0i);
this.fillTriangle();
break;
}
break;
}
}, "~N");
Clazz.defineMethod(c$, "drawArray", 
function(j0){
var info =  Clazz.newIntArray (16, 0);
var len = this.cgoMesh.getDrawArrayParams(j0 - 1, info);
this.glMode = info[0];
var nVertices = info[3];
var ptVertices = info[4];
var jColors = info[6];
this.cgoMesh.clearNormixList();
this.cgoMesh.clearColixList();
this.ptNormal = 0;
this.ptColor = 0;
this.nPts = 0;
for (var v = 0, j = ptVertices - 1; v < nVertices; v++, j += 3) {
if (jColors > 0) {
this.cgoMesh.addColix(jColors - 1 + v * 4);
this.getColix(true);
}this.processVertex(j);
}
return len;
}, "~N");
Clazz.defineMethod(c$, "getNormix", 
function(){
return (this.ptNormal >= this.cgoMesh.nList.size() ? 0 : this.cgoMesh.nList.get(this.ptNormal++).shortValue());
});
Clazz.defineMethod(c$, "getColix", 
function(doSet){
if (this.doColor) {
this.colix = JU.C.copyColixTranslucency(this.cgoMesh.colix, this.cgoMesh.cList.get(this.ptColor++).shortValue());
if (doSet) this.g3d.setC(this.colix);
}return this.colix;
}, "~B");
Clazz.defineMethod(c$, "getPoint", 
function(i, pt, pti){
this.cgoMesh.getPoint(i + 1, pt);
if (this.isMapped) {
var fx = (pt.x * this.scaleX - this.x0) / this.dx;
var fy = (pt.y * this.scaleY - this.y0) / this.dy;
pt.scaleAdd2(fx, this.vX, this.map0);
pt.scaleAdd2(fy, this.vY, pt);
} else if (this.is2D) {
pti.x = (this.is2DPercent ? this.tm.percentToPixels('x', pt.x) : Clazz.floatToInt(pt.x));
pti.y = (this.is2DPercent ? this.tm.percentToPixels('y', pt.y) : Clazz.floatToInt(pt.y));
pti.z = this.screenZ;
this.tm.unTransformPoint(pt, pt);
return;
}if (this.needVertices && !pt.equals(this.lastPoint)) {
var v = JU.P3.newP(pt);
this.vertexList.addLast(v);
this.lastPoint = v;
}this.tm.transformPtScr(pt, pti);
}, "~N,JU.P3,JU.P3i");
Clazz.defineMethod(c$, "fillTriangle", 
function(){
this.g3d.fillTriangle3CNBits(this.pt0, this.colix0, this.normix0, this.pt1, this.colix1, this.normix1, this.pt2, this.colix2, this.normix2, true);
});
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
