Clazz.declarePackage("J.adapter.readers.pdb");
Clazz.load(["J.adapter.readers.pdb.PdbReader"], "J.adapter.readers.pdb.JmolDataReader", ["java.util.Hashtable", "JU.Lst", "$.P3", "$.PT", "JS.T", "JU.C", "$.Escape", "$.Logger", "$.Parser", "$.Vibration"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.props = null;
this.residueNames = null;
this.atomNames = null;
this.isSpin = false;
this.spinFactor = 0;
this.originatingModel = -1;
this.jmolDataHeader = null;
this.jmolDataScaling = null;
Clazz.instantialize(this, arguments);}, J.adapter.readers.pdb, "JmolDataReader", J.adapter.readers.pdb.PdbReader);
Clazz.overrideMethod(c$, "checkRemark", 
function(){
while (true) {
if (this.line.length < 30 || this.line.indexOf("Jmol") != 11) break;
switch ("Ppard".indexOf(this.line.substring(16, 17))) {
case 0:
this.props =  new java.util.Hashtable();
this.isSpin = (this.line.indexOf(": spin;") >= 0);
this.originatingModel = -1;
var pt = this.line.indexOf("for model ");
if (pt > 0) this.originatingModel = JU.PT.parseInt(this.line.substring(pt + 10));
this.jmolDataHeader = this.line;
if (!this.line.endsWith("#noautobond")) this.line += "#noautobond";
break;
case 1:
var pt1 = this.line.indexOf("[");
var pt2 = this.line.indexOf("]");
if (pt1 < 25 || pt2 <= pt1) return;
var name = this.line.substring(25, pt1).trim();
this.line = this.line.substring(pt1 + 1, pt2).$replace(',', ' ');
var tokens = this.getTokens();
JU.Logger.info("reading " + name + " " + tokens.length);
var prop =  Clazz.newFloatArray (tokens.length, 0);
for (var i = prop.length; --i >= 0; ) prop[i] = this.parseFloatStr(tokens[i]);

this.props.put(name, prop);
break;
case 2:
this.line = this.line.substring(27);
this.atomNames = this.getTokens();
JU.Logger.info("reading atom names " + this.atomNames.length);
break;
case 3:
this.line = this.line.substring(30);
this.residueNames = this.getTokens();
JU.Logger.info("reading residue names " + this.residueNames.length);
break;
case 4:
JU.Logger.info(this.line);
var data =  Clazz.newFloatArray (15, 0);
JU.Parser.parseStringInfestedFloatArray(this.line.substring(10).$replace('=', ' ').$replace('{', ' ').$replace('}', ' '), null, data);
var minXYZ = JU.P3.new3(data[0], data[1], data[2]);
var maxXYZ = JU.P3.new3(data[3], data[4], data[5]);
this.fileScaling = JU.P3.new3(data[6], data[7], data[8]);
this.fileOffset = JU.P3.new3(data[9], data[10], data[11]);
var plotScale = JU.P3.new3(data[12], data[13], data[14]);
if (plotScale.x <= 0) plotScale.x = 100;
if (plotScale.y <= 0) plotScale.y = 100;
if (plotScale.z <= 0) plotScale.z = 100;
if (this.fileScaling.y == 0) this.fileScaling.y = 1;
if (this.fileScaling.z == 0) this.fileScaling.z = 1;
if (this.isSpin) {
this.spinFactor = plotScale.x / maxXYZ.x;
} else {
this.setFractionalCoordinates(true);
this.latticeCells =  Clazz.newIntArray (4, 0);
this.asc.xtalSymmetry = null;
this.setUnitCell(plotScale.x * 2 / (maxXYZ.x - minXYZ.x), plotScale.y * 2 / (maxXYZ.y - minXYZ.y), plotScale.z * 2 / (maxXYZ.z == minXYZ.z ? 1 : maxXYZ.z - minXYZ.z), 90, 90, 90);
this.unitCellOffset = JU.P3.newP(plotScale);
this.unitCellOffset.scale(-1);
this.getSymmetry();
this.symmetry.toFractional(this.unitCellOffset, false);
this.unitCellOffset.scaleAdd2(-1, minXYZ, this.unitCellOffset);
this.symmetry.setOffsetPt(this.unitCellOffset);
this.doApplySymmetry = true;
}this.jmolDataScaling =  Clazz.newArray(-1, [minXYZ, maxXYZ, plotScale]);
break;
}
break;
}
this.checkCurrentLineForScript();
});
Clazz.defineMethod(c$, "processAtom2", 
function(atom, serial, x, y, z, charge){
var vib = null;
if (this.isSpin) {
vib =  new JU.Vibration();
vib.set(x, y, z);
vib.isFrom000 = true;
atom.vib = vib;
x *= this.spinFactor;
y *= this.spinFactor;
z *= this.spinFactor;
}Clazz.superCall(this, J.adapter.readers.pdb.JmolDataReader, "processAtom2", [atom, serial, x, y, z, charge]);
if (vib != null) {
vib.spin = Clazz.floatToInt(atom.bfactor);
atom.bfactor = NaN;
}}, "J.adapter.smarter.Atom,~N,~N,~N,~N,~N");
Clazz.defineMethod(c$, "setAdditionalAtomParameters", 
function(atom){
Clazz.superCall(this, J.adapter.readers.pdb.JmolDataReader, "setAdditionalAtomParameters", [atom]);
if (this.residueNames != null && atom.index < this.residueNames.length) atom.group3 = this.residueNames[atom.index];
if (this.atomNames != null && atom.index < this.atomNames.length) atom.atomName = this.atomNames[atom.index];
}, "J.adapter.smarter.Atom");
Clazz.overrideMethod(c$, "finalizeSubclassReader", 
function(){
if (this.jmolDataHeader == null) return;
var info =  new java.util.Hashtable();
info.put("header", this.jmolDataHeader);
info.put("originatingModel", Integer.$valueOf(this.originatingModel));
info.put("properties", this.props);
info.put("jmolDataScaling", this.jmolDataScaling);
this.asc.setInfo("jmolData", info);
this.finalizeReaderPDB();
});
Clazz.defineMethod(c$, "getJmolDataFrameScripts", 
function(vwr, tok, modelIndex, modelCount, type, qFrame, props, isSpinPointGroup){
var script;
var script2 = null;
switch (tok) {
default:
script = "frame 0.0; frame last; reset;select visible;wireframe only;";
break;
case 1715472409:
vwr.setFrameTitle(modelCount - 1, type + " plot for model " + vwr.getModelNumberDotted(modelIndex));
script = "frame 0.0; frame last; reset;select visible; spacefill 3.0; wireframe 0;draw plotAxisX" + modelCount + " {100 -100 -100} {-100 -100 -100} \"" + props[0] + "\";" + "draw plotAxisY" + modelCount + " {-100 100 -100} {-100 -100 -100} \"" + props[1] + "\";";
if (props[2] != null) script += "draw plotAxisZ" + modelCount + " {-100 -100 100} {-100 -100 -100} \"" + props[2] + "\";";
break;
case 4138:
vwr.setFrameTitle(modelCount - 1, "ramachandran plot for model " + vwr.getModelNumberDotted(modelIndex));
script = "frame 0.0; frame last; reset;select visible; color structure; spacefill 3.0; wireframe 0;draw ramaAxisX" + modelCount + " {100 0 0} {-100 0 0} \"phi\";" + "draw ramaAxisY" + modelCount + " {0 100 0} {0 -100 0} \"psi\";";
break;
case 134221850:
case 136314895:
vwr.setFrameTitle(modelCount - 1, type.$replace('w', ' ') + qFrame + " for model " + vwr.getModelNumberDotted(modelIndex));
case 1095241729:
var color = (JU.C.getHexCode(vwr.cm.colixBackgroundContrast));
script = "frame 0.0; frame last; reset;select visible; wireframe 0; spacefill 3.0; isosurface quatSphere" + modelCount + " color " + color + " sphere 100.0 mesh nofill frontonly translucent 0.8;" + "draw quatAxis" + modelCount + "X {100 0 0} {-100 0 0} color red \"x\";" + "draw quatAxis" + modelCount + "Y {0 100 0} {0 -100 0} color green \"y\";" + "draw quatAxis" + modelCount + "Z {0 0 100} {0 0 -100} color blue \"z\";" + (tok == 1095241729 ? "vectors 2.0;spacefill off;" : "color structure;") + "draw quatCenter" + modelCount + "{0 0 0} scale 0.02;";
if (isSpinPointGroup) {
script2 = ";set symmetryhm;draw spin pointgroup;var name = {2.1}.pointgroup().hmName;set echo hmname 100% 100%;set echo hmname RIGHT;set echo hmname model 2.1;echo @name;";
}break;
}
return  Clazz.newArray(-1, [script, script2]);
}, "JV.Viewer,~N,~N,~N,~S,~S,~A,~B");
Clazz.defineMethod(c$, "getJmolDataFrameProperties", 
function(e, tok, propToks, props, bs, minXYZ, maxXYZ, format, isPdbFormat){
var pdbFactor = 1;
var dataX = null;
var dataY = null;
var dataZ = null;
var data3 = null;
dataX = e.getBitsetPropertyFloat(bs, propToks[0] | 224, propToks[0] == 1715472409 ? props[0] : null, (minXYZ == null ? NaN : minXYZ.x), (maxXYZ == null ? NaN : maxXYZ.x));
var propData =  new Array(3);
propData[0] = props[0] + " " + JU.Escape.eAF(dataX);
if (props[1] != null) {
dataY = e.getBitsetPropertyFloat(bs, propToks[1] | 224, propToks[1] == 1715472409 ? props[1] : null, (minXYZ == null ? NaN : minXYZ.y), (maxXYZ == null ? NaN : maxXYZ.y));
propData[1] = props[1] + " " + JU.Escape.eAF(dataY);
}if (props[2] != null) {
dataZ = e.getBitsetPropertyFloat(bs, propToks[2] | 224, propToks[2] == 1715472409 ? props[2] : null, (minXYZ == null ? NaN : minXYZ.z), (maxXYZ == null ? NaN : maxXYZ.z));
propData[2] = props[2] + " " + JU.Escape.eAF(dataZ);
}if (props[3] != null) {
data3 = e.getBitsetPropertyFloat(bs, propToks[3] | 224, propToks[3] == 1715472409 ? props[3] : null, NaN, NaN);
propData[3] = props[3] + " " + JU.Escape.eAF(data3);
}if (minXYZ == null) minXYZ = JU.P3.new3(J.adapter.readers.pdb.JmolDataReader.getPlotMinMax(dataX, false, propToks[0]), J.adapter.readers.pdb.JmolDataReader.getPlotMinMax(dataY, false, propToks[1]), J.adapter.readers.pdb.JmolDataReader.getPlotMinMax(dataZ, false, propToks[2]));
if (maxXYZ == null) maxXYZ = JU.P3.new3(J.adapter.readers.pdb.JmolDataReader.getPlotMinMax(dataX, true, propToks[0]), J.adapter.readers.pdb.JmolDataReader.getPlotMinMax(dataY, true, propToks[1]), J.adapter.readers.pdb.JmolDataReader.getPlotMinMax(dataZ, true, propToks[2]));
JU.Logger.info("plot min/max: " + minXYZ + " " + maxXYZ);
var center = null;
var factors = null;
if (isPdbFormat) {
factors = JU.P3.new3(1, 1, 1);
center =  new JU.P3();
center.ave(maxXYZ, minXYZ);
factors.sub2(maxXYZ, minXYZ);
if (tok != 1095241729) factors.set(factors.x / 200, factors.y / 200, factors.z / 200);
if (JS.T.tokAttr(propToks[0], 1094713344)) {
factors.x = 1;
center.x = 0;
} else if (factors.x > 0.1 && factors.x <= 10) {
factors.x = 1;
}if (JS.T.tokAttr(propToks[1], 1094713344)) {
factors.y = 1;
center.y = 0;
} else if (factors.y > 0.1 && factors.y <= 10) {
factors.y = 1;
}if (JS.T.tokAttr(propToks[2], 1094713344)) {
factors.z = 1;
center.z = 0;
} else if (factors.z > 0.1 && factors.z <= 10) {
factors.z = 1;
}if (props[2] == null || props[1] == null) center.z = minXYZ.z = maxXYZ.z = factors.z = 0;
for (var i = 0; i < dataX.length; i++) dataX[i] = (dataX[i] - center.x) / factors.x * pdbFactor;

if (props[1] != null) for (var i = 0; i < dataY.length; i++) dataY[i] = (dataY[i] - center.y) / factors.y * pdbFactor;

if (props[2] != null) for (var i = 0; i < dataZ.length; i++) dataZ[i] = (dataZ[i] - center.z) / factors.z * pdbFactor;

}return  Clazz.newArray(-1, [bs, dataX, dataY, dataZ, minXYZ, maxXYZ, factors, center, format, propData, Float.$valueOf(1)]);
}, "JS.ScriptEval,~N,~A,~A,JU.BS,JU.P3,JU.P3,~S,~B");
c$.getPlotMinMax = Clazz.defineMethod(c$, "getPlotMinMax", 
function(data, isMax, tok){
if (data == null) return 0;
switch (tok) {
case 1111490568:
case 1111490569:
case 1111490570:
return (isMax ? 180 : -180);
case 1111490565:
case 1111490576:
return (isMax ? 360 : 0);
case 1111490574:
return (isMax ? 1 : -1);
}
var fmax = (isMax ? -1.0E10 : 1E10);
for (var i = data.length; --i >= 0; ) {
var f = data[i];
if (Float.isNaN(f)) continue;
if (isMax == (f > fmax)) fmax = f;
}
return fmax;
}, "~A,~B,~N");
Clazz.defineMethod(c$, "setJmolDataFrame", 
function(ms, type, modelIndex, modelDataIndex){
ms.haveJmolDataFrames = true;
var mdata = ms.am[modelDataIndex];
var model0 = ms.am[type == null ? mdata.dataSourceFrame : modelIndex];
if (type == null) {
type = mdata.jmolFrameType;
}if (modelIndex >= 0) {
if (model0.dataFrames == null) {
model0.dataFrames =  new java.util.Hashtable();
}mdata.dataSourceFrame = modelIndex;
mdata.jmolFrameType = type;
model0.dataFrames.put(type, Integer.$valueOf(modelDataIndex));
if (mdata.jmolFrameTypeInt == 134221850 && type.indexOf("deriv") < 0) {
type = type.substring(0, type.indexOf(" "));
model0.dataFrames.put(type, Integer.$valueOf(modelDataIndex));
}mdata.uvw0 = ms.getModelAuxiliaryInfo(modelIndex).get("pointGroupAxes");
if (mdata.uvw0 != null) {
mdata.uvw0[0].scale(105);
mdata.uvw0[1].scale(105);
mdata.uvw0[2].scale(105);
mdata.uvw =  Clazz.newArray(-1, [JU.P3.newP(mdata.uvw0[0]), JU.P3.newP(mdata.uvw0[1]), JU.P3.newP(mdata.uvw0[2])]);
}}}, "JM.ModelSet,~S,~N,~N");
Clazz.defineMethod(c$, "getPlotSpinSet", 
function(vwr, bs, modelIndex, minXYZ, maxXYZ){
if (bs.nextSetBit(0) < 0) {
bs = vwr.getModelUndeletedAtomsBitSet(modelIndex);
}if (bs.isEmpty()) return null;
var len = 0;
for (var i = bs.nextSetBit(0); i >= 0; i = bs.nextSetBit(i + 1)) {
var v = vwr.ms.getVibration(i, false);
if (v == null) bs.clear(i);
 else len = Math.max(len, v.length());
}
if (len == 0) return null;
minXYZ.set(-len, -len, -len);
maxXYZ.set(len, len, len);
var lst =  new JU.Lst();
for (var i = bs.nextSetBit(0); i >= 0; i = bs.nextSetBit(i + 1)) {
var v = vwr.ms.getVibration(i, false);
var spin = vwr.ms.getSpin(i);
v.spin = spin;
var found = false;
for (var j = lst.size(); --j >= 0; ) {
if (v.distance(lst.get(j)) < 0.1) {
found = true;
bs.clear(i);
break;
}}
if (!found) lst.addLast(v);
}
return bs;
}, "JV.Viewer,JU.BS,~N,JU.P3,JU.P3");
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
