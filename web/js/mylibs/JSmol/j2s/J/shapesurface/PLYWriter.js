Clazz.declarePackage("J.shapesurface");
Clazz.load(null, "J.shapesurface.PLYWriter", ["JU.BS", "JU.C", "JV.Viewer"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.imesh = null;
this.isBinary = false;
this.writeShorts = false;
this.oc = null;
this.polygonIndexes = null;
this.selectedPolyOnly = false;
this.bsPolygons = null;
this.haveBsDisplay = false;
this.vertexCount = 0;
this.mapJmolToPLY = null;
this.mapPLYToJmol = null;
Clazz.instantialize(this, arguments);}, J.shapesurface, "PLYWriter", null);
/*LV!1824 unnec constructor*/Clazz.defineMethod(c$, "write", 
function(isosurfaceMesh, isBinary){
this.imesh = isosurfaceMesh;
this.isBinary = isBinary;
this.setup();
var bsPoly =  new JU.BS();
var bsVert =  new JU.BS();
this.checkTriangles(bsPoly, bsVert);
var nP = bsPoly.cardinality();
var nV = bsVert.cardinality();
this.mapJmolToPLY =  Clazz.newIntArray (this.vertexCount, 0);
this.mapPLYToJmol =  Clazz.newIntArray (nV, 0);
for (var v = 0, i = bsVert.nextSetBit(0); i >= 0; i = bsVert.nextSetBit(i + 1)) {
this.mapPLYToJmol[v] = i;
this.mapJmolToPLY[i] = v++;
}
this.writeShorts = (nV <= 32767);
this.writePLYHeader(nV, nP);
this.writeVertices(nV);
this.writeTriangles(bsPoly);
this.oc.closeChannel();
return (isBinary ? this.oc.toByteArray() : this.oc.toString());
}, "J.shapesurface.IsosurfaceMesh,~B");
Clazz.defineMethod(c$, "writeVertices", 
function(nV){
var haveVertexColors = (!this.imesh.isColorSolid && this.imesh.vcs != null);
var cx = 0;
if (!haveVertexColors) cx = this.imesh.colix;
for (var i = 0; i < nV; i++) {
if (haveVertexColors) {
cx = this.imesh.vcs[this.mapPLYToJmol[i]];
}var color = JU.C.getArgb(cx);
this.outputXYZ(this.imesh.vs[this.mapPLYToJmol[i]], color);
}
}, "~N");
Clazz.defineMethod(c$, "writeTriangles", 
function(bsPoly){
for (var i = bsPoly.nextSetBit(0); i >= 0; i = bsPoly.nextSetBit(i + 1)) {
var polygon = this.polygonIndexes[i];
var iA = this.mapJmolToPLY[polygon[0]];
var iB = this.mapJmolToPLY[polygon[1]];
var iC = this.mapJmolToPLY[polygon[2]];
this.outputTriangle(iA, iB, iC);
}
}, "JU.BS");
Clazz.defineMethod(c$, "checkTriangles", 
function(bsPoly, bsVert){
for (var i = this.imesh.pc; --i >= 0; ) {
var polygon = this.polygonIndexes[i];
if (polygon == null || this.selectedPolyOnly && !this.bsPolygons.get(i)) continue;
var iA = polygon[0];
if (this.imesh.jvxlData.thisSet != null && this.imesh.vertexSets != null && !this.imesh.jvxlData.thisSet.get(this.imesh.vertexSets[iA])) continue;
var iB = polygon[1];
var iC = polygon[2];
if (this.haveBsDisplay && (!this.imesh.bsDisplay.get(iA) || !this.imesh.bsDisplay.get(iB) || !this.imesh.bsDisplay.get(iC))) continue;
if (iA == iB || iB == iC || iA == iC) continue;
bsPoly.set(i);
bsVert.set(iA);
bsVert.set(iB);
bsVert.set(iC);
}
}, "JU.BS,JU.BS");
Clazz.defineMethod(c$, "setup", 
function(){
this.oc = this.imesh.vwr.getOutputChannel(null, null);
if (this.isBinary) this.oc.setBigEndian(false);
this.vertexCount = this.imesh.vc;
this.polygonIndexes = this.imesh.pis;
this.haveBsDisplay = (this.imesh.bsDisplay != null);
this.selectedPolyOnly = (this.imesh.bsSlabDisplay != null);
this.bsPolygons = (this.selectedPolyOnly ? this.imesh.bsSlabDisplay : null);
});
Clazz.defineMethod(c$, "writePLYHeader", 
function(nV, nT){
var format = (this.isBinary ? "binary_little_endian" : "ascii");
this.oc.append("ply\nformat " + format + " 1.0\n" + "comment Created by Jmol " + JV.Viewer.getJmolVersion() + "\n" + "element vertex " + nV + "\n" + "property float x\n" + "property float y\n" + "property float z\n" + "property uchar red\n" + "property uchar green\n" + "property uchar blue\n" + "element face " + nT + "\n" + "property list uchar " + (this.writeShorts ? "short" : "int") + " vertex_indices\n" + "end_header\n");
}, "~N,~N");
Clazz.defineMethod(c$, "outputInt", 
function(i){
if (this.isBinary) this.oc.writeInt(i);
 else this.oc.append(" " + i);
}, "~N");
Clazz.defineMethod(c$, "outputXYZ", 
function(pt, color){
var r = (color >> 16) & 0xFF;
var g = (color >> 8) & 0xFF;
var b = (color) & 0xFF;
if (this.isBinary) {
this.oc.writeFloat(pt.x);
this.oc.writeFloat(pt.y);
this.oc.writeFloat(pt.z);
this.oc.writeByteAsInt(r);
this.oc.writeByteAsInt(g);
this.oc.writeByteAsInt(b);
} else {
this.oc.append(pt.x + " " + pt.y + " " + pt.z + " " + r + " " + g + " " + b + "\n");
}}, "JU.T3,~N");
Clazz.defineMethod(c$, "outputTriangle", 
function(iA, iB, iC){
if (this.isBinary) {
this.oc.writeByteAsInt(3);
if (this.writeShorts) {
this.oc.writeShort(iA);
this.oc.writeShort(iB);
this.oc.writeShort(iC);
} else {
this.outputInt(iA);
this.outputInt(iB);
this.outputInt(iC);
}} else {
this.oc.append("3 " + iA + " " + iB + " " + iC + "\n");
}}, "~N,~N,~N");
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
