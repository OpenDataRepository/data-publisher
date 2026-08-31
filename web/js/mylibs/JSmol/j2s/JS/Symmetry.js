Clazz.declarePackage("JS");
Clazz.load(["J.api.SymmetryInterface"], "JS.Symmetry", ["java.util.Hashtable", "JU.AU", "$.BS", "$.JSJSONParser", "$.Lst", "$.M4", "$.P3", "$.PT", "$.Quat", "$.Rdr", "$.SB", "J.api.Interface", "J.bspt.Bspt", "JS.PointGroup", "$.SpaceGroup", "$.SymmetryInfo", "$.SymmetryOperation", "$.UnitCell", "JU.BSUtil", "$.Escape", "$.Logger", "$.SimpleUnitCell", "JV.FileManager"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.spaceGroup = null;
this.unitCell = null;
this.$isBio = false;
this.id = null;
this.vwr = null;
this.spinSym = null;
this.pointGroup = null;
this.cip = null;
this.symmetryInfo = null;
this.desc = null;
this.transformMatrix = null;
this.spinFrameToCartXYZ = null;
Clazz.instantialize(this, arguments);}, JS, "Symmetry", null, J.api.SymmetryInterface);
/*LV!1824 unnec constructor*/Clazz.overrideMethod(c$, "getSymopList", 
function(doNormalize){
var n = this.spaceGroup.operationCount;
var list =  new Array(n);
for (var i = 0; i < n; i++) list[i] = "" + this.getSpaceGroupXyzOriginal(i, doNormalize);

return list;
}, "~B");
Clazz.overrideMethod(c$, "isBio", 
function(){
return this.$isBio;
});
Clazz.overrideMethod(c$, "setPointGroup", 
function(vwr, siLast, center, atomset, bsAtoms, haveVibration, distanceTolerance, linearTolerance, maxAtoms, localEnvOnly){
this.pointGroup = JS.PointGroup.getPointGroup(siLast == null ? null : (siLast).pointGroup, center, atomset, bsAtoms, haveVibration, distanceTolerance, linearTolerance, maxAtoms, localEnvOnly, vwr.getBoolean(603979956), vwr.getScalePixelsPerAngstrom(false));
return this;
}, "JV.Viewer,J.api.SymmetryInterface,JU.T3,~A,JU.BS,~B,~N,~N,~N,~B");
Clazz.overrideMethod(c$, "getPointGroupName", 
function(){
return this.pointGroup.getName();
});
Clazz.overrideMethod(c$, "getPointGroupInfo", 
function(modelIndex, drawID, asInfo, type, index, scale){
return this.pointGroup.getInfo(modelIndex, null, null, drawID, asInfo, type, index, scale);
}, "~N,~S,~B,~S,~N,~N");
Clazz.overrideMethod(c$, "setSpaceGroup", 
function(doNormalize){
if (this.spaceGroup == null) this.spaceGroup = JS.SpaceGroup.getNull(true, doNormalize, false);
}, "~B");
Clazz.overrideMethod(c$, "addSpaceGroupOperation", 
function(xyz, opId){
return this.spaceGroup.addSymmetry(xyz, opId, true);
}, "~S,~N");
Clazz.overrideMethod(c$, "addBioMoleculeOperation", 
function(mat, isReverse){
this.$isBio = this.spaceGroup.isBio = true;
return this.spaceGroup.addSymmetry((isReverse ? "!" : "") + "[[bio" + mat, 0, false);
}, "JU.M4,~B");
Clazz.overrideMethod(c$, "setLattice", 
function(latt){
this.spaceGroup.setLatticeParam(latt);
}, "~N");
Clazz.overrideMethod(c$, "getSpaceGroup", 
function(){
return this.spaceGroup;
});
Clazz.overrideMethod(c$, "getSpaceGroupInfoObj", 
function(name, params, isFull, addNonstandard){
var isNumOrTrm = false;
switch (name) {
case "list":
return this.getSpaceGroupList(params);
case "opsCtr":
return this.spaceGroup.getOpsCtr(params);
case "itaTransform":
case "itaNumber":
isNumOrTrm = true;
case "nameToXYZList":
case "itaIndex":
case "hmName":
case "hmNameShort":
var sg = null;
if (params != null) {
var s = params;
if (s.endsWith("'")) {
s = JS.SpaceGroup.convertWyckoffHMCleg(s, null);
if (isNumOrTrm && s != null) {
var pt = s.indexOf(":");
return ("itaNumber".equals(name) ? s.substring(0, pt) : s.substring(pt + 1));
}return null;
}if (s.length > 1 && s.charAt(1) == '/') {
var specialType = JS.SpaceGroup.getExplicitSpecialGroupType(s);
var info = JS.Symmetry.getSpecialSettingInfo(this.vwr, s, specialType);
if (info != null) {
switch (name) {
case "itaData":
return info;
case "hmName":
case "hmNameShort":
return info.get("hm");
case "nameToXYZList":
return info.get("gp");
case "itaIndex":
return "" + info.get("sg") + "." + info.get("set");
case "itaTransform":
return info.get("trm");
case "itaNumber":
return "" + info.get("sg");
}
}return null;
}if (s.startsWith("ITA/")) s = s.substring(4);
sg = JS.SpaceGroup.determineSpaceGroupN(s);
if (sg == null && "nameToXYZList".equals(name)) sg = JS.SpaceGroup.createSpaceGroupN(s, true);
} else if (this.spaceGroup != null) {
sg = this.spaceGroup;
} else if (this.symmetryInfo != null) {
sg = this.symmetryInfo.getDerivedSpaceGroup();
}switch (sg == null ? "" : name) {
case "hmName":
return sg.getHMName();
case "hmNameShort":
return sg.getHMNameShort();
case "nameToXYZList":
var genPos =  new JU.Lst();
sg.setFinalOperationsSafely();
for (var i = 0, n = sg.getOperationCount(); i < n; i++) {
genPos.addLast((sg.getOperation(i)).xyz);
}
return genPos;
case "itaIndex":
return sg.getItaIndex();
case "itaTransform":
return sg.itaTransform;
case "itaNumber":
return sg.itaNumber;
}
return null;
default:
return JS.SpaceGroup.getInfo(this.spaceGroup, name, params, isFull, addNonstandard);
}
}, "~S,~O,~B,~B");
Clazz.defineMethod(c$, "getSpaceGroupList", 
function(sg0){
var sb =  new JU.SB();
var list = this.getSpaceGroupJSON("ITA", "ALL", 0);
for (var i = 0, n = list.size(); i < n; i++) {
var map = list.get(i);
var sg = map.get("sg");
if (sg0 == null || sg.equals(sg0)) sb.appendO(sg).appendC('.').appendO(map.get("set")).appendC('\t').appendO(map.get("hm")).appendC('\t').appendO(map.get("sg")).appendC(':').appendO(map.get("trm")).appendC('\n');
}
return sb.toString();
}, "Integer");
Clazz.overrideMethod(c$, "getLatticeDesignation", 
function(){
return this.spaceGroup.getShelxLATTDesignation();
});
Clazz.overrideMethod(c$, "setFinalOperations", 
function(dim, name, atoms, iAtomFirst, noSymmetryCount, doNormalize, filterSymop){
if (name != null && (name.startsWith("bio") || name.indexOf(" *(") >= 0)) this.spaceGroup.setName(name);
var doCalculate = "unspecified!".equals(name);
if (doCalculate) filterSymop = "calculated";
if (filterSymop != null) {
var lst =  new JU.Lst();
lst.addLast(this.spaceGroup.symmetryOperations[0]);
for (var i = 1; i < this.spaceGroup.operationCount; i++) if (doCalculate || filterSymop.contains(" " + (i + 1) + " ")) lst.addLast(this.spaceGroup.symmetryOperations[i]);

this.spaceGroup = JS.SpaceGroup.createSpaceGroup(-1, name + " *(" + filterSymop.trim() + ")", lst, -1);
}this.spaceGroup.setFinalOperationsForAtoms(dim, atoms, iAtomFirst, noSymmetryCount, doNormalize);
}, "~N,~S,~A,~N,~N,~B,~S");
Clazz.overrideMethod(c$, "getSpaceGroupOperation", 
function(i){
return (this.spaceGroup == null || this.spaceGroup.symmetryOperations == null || i >= this.spaceGroup.symmetryOperations.length ? null : this.spaceGroup.finalOperations == null ? this.spaceGroup.symmetryOperations[i] : this.spaceGroup.finalOperations[i]);
}, "~N");
Clazz.overrideMethod(c$, "getSpaceGroupXyzOriginal", 
function(i, doNormalize){
return this.spaceGroup.getXyz(i, doNormalize);
}, "~N,~B");
Clazz.overrideMethod(c$, "newSpaceGroupPoint", 
function(pt, i, o, transX, transY, transZ, retPoint){
if (o == null && this.spaceGroup.finalOperations == null) {
var op = this.spaceGroup.symmetryOperations[i];
op.doFinalize();
o = op;
}JS.SymmetryOperation.rotateAndTranslatePoint((o == null ? this.spaceGroup.finalOperations[i] : o), pt, transX, transY, transZ, retPoint);
}, "JU.P3,~N,JU.M4,~N,~N,~N,JU.P3");
Clazz.overrideMethod(c$, "getSpinOp", 
function(op){
return this.spaceGroup.symmetryOperations[op].getMagneticOp();
}, "~N");
Clazz.overrideMethod(c$, "getLatticeOp", 
function(){
return this.spaceGroup.latticeOp;
});
Clazz.overrideMethod(c$, "getLatticeCentering", 
function(){
return JS.SymmetryOperation.getLatticeCentering(this.getSymmetryOperations());
});
Clazz.overrideMethod(c$, "getOperationRsVs", 
function(iop){
return (this.spaceGroup.finalOperations == null ? this.spaceGroup.symmetryOperations : this.spaceGroup.finalOperations)[iop].rsvs;
}, "~N");
Clazz.overrideMethod(c$, "getSiteMultiplicity", 
function(pt){
return this.spaceGroup.getSiteMultiplicity(pt, this.unitCell);
}, "JU.P3");
Clazz.overrideMethod(c$, "getSpaceGroupName", 
function(){
return (this.spaceGroup != null ? this.spaceGroup.getName() : this.symmetryInfo != null ? this.symmetryInfo.sgName : this.unitCell != null && this.unitCell.name.length > 0 ? "cell=" + this.unitCell.name : "");
});
Clazz.overrideMethod(c$, "geCIFWriterValue", 
function(type){
return (this.spaceGroup == null ? null : this.spaceGroup.getCIFWriterValue(type, this));
}, "~S");
Clazz.overrideMethod(c$, "getLatticeType", 
function(){
return (this.symmetryInfo != null ? this.symmetryInfo.latticeType : this.spaceGroup == null ? 'P' : this.spaceGroup.latticeType);
});
Clazz.overrideMethod(c$, "getIntTableNumber", 
function(){
return (this.symmetryInfo != null ? this.symmetryInfo.intlTableNo : this.spaceGroup == null ? null : this.spaceGroup.itaNumber);
});
Clazz.overrideMethod(c$, "getIntTableIndex", 
function(){
return (this.symmetryInfo != null ? this.symmetryInfo.intlTableIndexNdotM : this.spaceGroup == null ? null : this.spaceGroup.getItaIndex());
});
Clazz.overrideMethod(c$, "getIntTableTransform", 
function(){
return (this.symmetryInfo != null ? this.symmetryInfo.intlTableTransform : this.spaceGroup == null ? null : this.spaceGroup.itaTransform);
});
Clazz.overrideMethod(c$, "getSpaceGroupClegId", 
function(){
return (this.symmetryInfo != null ? this.symmetryInfo.getClegId() : this.spaceGroup.getClegId());
});
Clazz.overrideMethod(c$, "getSpaceGroupJmolId", 
function(){
return (this.symmetryInfo != null ? this.symmetryInfo.intlTableJmolId : this.spaceGroup == null ? null : this.spaceGroup.jmolId);
});
Clazz.overrideMethod(c$, "getCoordinatesAreFractional", 
function(){
return this.symmetryInfo == null || this.symmetryInfo.coordinatesAreFractional;
});
Clazz.overrideMethod(c$, "getCellRange", 
function(){
return this.symmetryInfo == null ? null : this.symmetryInfo.cellRange;
});
Clazz.overrideMethod(c$, "getSymmetryInfoStr", 
function(){
if (this.symmetryInfo != null) return this.symmetryInfo.infoStr;
if (this.spaceGroup == null) return "";
(this.symmetryInfo =  new JS.SymmetryInfo(this.spaceGroup)).setSymmetryInfoFromModelkit(this.spaceGroup);
return this.symmetryInfo.infoStr;
});
Clazz.overrideMethod(c$, "getSpaceGroupOperationCount", 
function(){
return (this.symmetryInfo != null && this.symmetryInfo.symmetryOperations != null ? this.symmetryInfo.symmetryOperations.length : this.spaceGroup != null ? (this.spaceGroup.finalOperations != null ? this.spaceGroup.finalOperations.length : this.spaceGroup.operationCount) : 0);
});
Clazz.overrideMethod(c$, "getSymmetryOperations", 
function(){
if (this.symmetryInfo != null) return this.symmetryInfo.symmetryOperations;
if (this.spaceGroup == null) this.spaceGroup = JS.SpaceGroup.getNull(true, false, true);
this.spaceGroup.setFinalOperationsSafely();
return this.spaceGroup.finalOperations;
});
Clazz.overrideMethod(c$, "getAdditionalOperationsCount", 
function(){
return (this.symmetryInfo != null && this.symmetryInfo.symmetryOperations != null && this.symmetryInfo.getAdditionalOperations() != null ? this.symmetryInfo.additionalOperations.length : this.spaceGroup != null && this.spaceGroup.finalOperations != null ? this.spaceGroup.getAdditionalOperationsCount() : 0);
});
Clazz.overrideMethod(c$, "getAdditionalOperations", 
function(){
if (this.symmetryInfo != null) return this.symmetryInfo.getAdditionalOperations();
this.getSymmetryOperations();
return this.spaceGroup.getAdditionalOperations();
});
Clazz.overrideMethod(c$, "isSimple", 
function(){
return (this.spaceGroup == null && (this.symmetryInfo == null || this.symmetryInfo.symmetryOperations == null));
});
Clazz.overrideMethod(c$, "haveUnitCell", 
function(){
return (this.unitCell != null);
});
Clazz.overrideMethod(c$, "setUnitCellFromParams", 
function(unitCellParams, setRelative, slop){
if (unitCellParams == null) unitCellParams =  Clazz.newFloatArray(-1, [1, 1, 1, 90, 90, 90]);
this.unitCell = JS.UnitCell.fromParams(unitCellParams, setRelative, slop);
return this;
}, "~A,~B,~N");
Clazz.overrideMethod(c$, "unitCellEquals", 
function(uc2){
return ((uc2)).unitCell.isSameAs(this.unitCell.getF2C());
}, "J.api.SymmetryInterface");
Clazz.overrideMethod(c$, "isSymmetryCell", 
function(sym){
var uc = ((sym)).unitCell;
var myf2c = (!uc.isStandard() ? null : (this.symmetryInfo != null ? this.symmetryInfo.spaceGroupF2C : this.unitCell.getF2C()));
var ret = uc.isSameAs(myf2c);
if (this.symmetryInfo != null) {
if (this.symmetryInfo.setIsCurrentCell(ret)) {
this.setUnitCellFromParams(this.symmetryInfo.spaceGroupF2CParams, false, NaN);
}}return ret;
}, "J.api.SymmetryInterface");
Clazz.overrideMethod(c$, "getUnitCellState", 
function(){
if (this.unitCell == null) return "";
return this.unitCell.getState();
});
Clazz.overrideMethod(c$, "getMoreInfo", 
function(){
return this.unitCell.getMoreInfo();
});
Clazz.overrideMethod(c$, "initializeOrientation", 
function(mat){
this.unitCell.initOrientation(mat);
}, "JU.M3");
Clazz.overrideMethod(c$, "unitize", 
function(ptFrac){
this.unitCell.unitize(ptFrac);
}, "JU.T3");
Clazz.overrideMethod(c$, "toUnitCell", 
function(pt, offset){
this.unitCell.toUnitCell(pt, offset);
}, "JU.T3,JU.T3");
Clazz.overrideMethod(c$, "toSupercell", 
function(fpt){
return this.unitCell.toSupercell(fpt);
}, "JU.P3");
Clazz.defineMethod(c$, "toFractional", 
function(pt, ignoreOffset){
if (!this.$isBio) this.unitCell.toFractional(pt, ignoreOffset);
}, "JU.T3,~B");
Clazz.overrideMethod(c$, "toCartesian", 
function(pt, ignoreOffset){
if (!this.$isBio) this.unitCell.toCartesian(pt, ignoreOffset);
}, "JU.T3,~B");
Clazz.overrideMethod(c$, "getUnitCellParams", 
function(){
return this.unitCell.getUnitCellParams();
});
Clazz.overrideMethod(c$, "getUnitCellAsArray", 
function(vectorsOnly){
return this.unitCell.getUnitCellAsArray(vectorsOnly);
}, "~B");
Clazz.overrideMethod(c$, "getUnitCellVerticesNoOffset", 
function(){
return this.unitCell.getVertices();
});
Clazz.overrideMethod(c$, "getCartesianOffset", 
function(){
return this.unitCell.getCartesianOffset();
});
Clazz.overrideMethod(c$, "getFractionalOffset", 
function(onlyIfFractional){
var offset = this.unitCell.getFractionalOffset();
return (onlyIfFractional && offset != null && offset.x == Clazz.floatToInt(offset.x) && offset.y == Clazz.floatToInt(offset.y) && offset.z == Clazz.floatToInt(offset.z) ? null : offset);
}, "~B");
Clazz.overrideMethod(c$, "setOffsetPt", 
function(pt){
this.unitCell.setOffset(pt);
}, "JU.T3");
Clazz.overrideMethod(c$, "setOffset", 
function(nnn){
var pt =  new JU.P3();
JU.SimpleUnitCell.ijkToPoint3f(nnn, pt, 0, 0);
this.unitCell.setOffset(pt);
}, "~N");
Clazz.overrideMethod(c$, "getUnitCellMultiplier", 
function(){
return this.unitCell.getUnitCellMultiplier();
});
Clazz.overrideMethod(c$, "getUnitCellMultiplied", 
function(){
var uc = this.unitCell.getUnitCellMultiplied();
if (uc === this.unitCell) return this;
var sym =  new JS.Symmetry().setViewer(this.vwr, "getUCM");
sym.unitCell = uc;
return sym;
});
Clazz.overrideMethod(c$, "getCanonicalCopy", 
function(scale, withOffset){
return this.unitCell.getCanonicalCopy(scale, withOffset);
}, "~N,~B");
Clazz.overrideMethod(c$, "getUnitCellInfoType", 
function(infoType){
return this.unitCell.getInfo(infoType);
}, "~N");
Clazz.overrideMethod(c$, "getUnitCellInfoStr", 
function(type){
return this.unitCell.getInfoStr(type);
}, "~S");
Clazz.overrideMethod(c$, "getUnitCellInfo", 
function(scaled){
return (this.unitCell == null ? null : this.unitCell.dumpInfo(false, scaled));
}, "~B");
Clazz.overrideMethod(c$, "isSlab", 
function(){
return this.unitCell.isSlab();
});
Clazz.overrideMethod(c$, "isPolymer", 
function(){
return this.unitCell.isPolymer();
});
Clazz.defineMethod(c$, "getUnitCellVectors", 
function(){
return this.unitCell.getUnitCellVectors();
});
Clazz.overrideMethod(c$, "getUnitCell", 
function(oabc, setRelative, name){
if (oabc == null) return null;
this.unitCell = JS.UnitCell.fromOABC(oabc, setRelative);
if (name != null) this.unitCell.name = name;
return this;
}, "~A,~B,~S");
Clazz.overrideMethod(c$, "isSupercell", 
function(){
return this.unitCell.isSupercell();
});
Clazz.overrideMethod(c$, "notInCentroid", 
function(modelSet, bsAtoms, minmax){
try {
var bsDelete =  new JU.BS();
var iAtom0 = bsAtoms.nextSetBit(0);
var molecules = modelSet.getMolecules();
var moleculeCount = molecules.length;
var atoms = modelSet.at;
var isOneMolecule = (molecules[moleculeCount - 1].firstAtomIndex == modelSet.am[atoms[iAtom0].mi].firstAtomIndex);
var center =  new JU.P3();
var packing = minmax[6] / 100;
var centroidPacked = (packing != 0);
nextMol : for (var i = moleculeCount; --i >= 0 && bsAtoms.get(molecules[i].firstAtomIndex); ) {
var bs = molecules[i].atomList;
center.set(0, 0, 0);
var n = 0;
for (var j = bs.nextSetBit(0); j >= 0; j = bs.nextSetBit(j + 1)) {
if (isOneMolecule || centroidPacked) {
center.setT(atoms[j]);
if (this.isNotCentroid(center, 1, minmax, packing)) {
if (isOneMolecule) bsDelete.set(j);
} else if (!isOneMolecule) {
continue nextMol;
}} else {
center.add(atoms[j]);
n++;
}}
if (centroidPacked || n > 0 && this.isNotCentroid(center, n, minmax, 0)) bsDelete.or(bs);
}
return bsDelete;
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
return null;
} else {
throw e;
}
}
}, "JM.ModelSet,JU.BS,~A");
Clazz.defineMethod(c$, "isNotCentroid", 
function(center, n, minmax, packing){
center.scale(1 / n);
this.toFractional(center, false);
if (packing != 0) {
var d = (packing >= 0 ? packing : 0.000005);
return (center.x + d <= minmax[0] || center.x - d > minmax[3] || center.y + d <= minmax[1] || center.y - d > minmax[4] || center.z + d <= minmax[2] || center.z - d > minmax[5]);
}return (center.x + 0.000005 <= minmax[0] || center.x + 0.00005 > minmax[3] || center.y + 0.000005 <= minmax[1] || center.y + 0.00005 > minmax[4] || center.z + 0.000005 <= minmax[2] || center.z + 0.00005 > minmax[5]);
}, "JU.P3,~N,~A,~N");
Clazz.defineMethod(c$, "getDesc", 
function(modelSet){
if (modelSet == null) {
return (JS.Symmetry.nullDesc == null ? (JS.Symmetry.nullDesc = (J.api.Interface.getInterface("JS.SymmetryDesc", null, "modelkit"))) : JS.Symmetry.nullDesc);
}return (this.desc == null ? (this.desc = (J.api.Interface.getInterface("JS.SymmetryDesc", modelSet.vwr, "eval"))) : this.desc).set(modelSet);
}, "JM.ModelSet");
Clazz.overrideMethod(c$, "getSymmetryInfoAtom", 
function(modelSet, iatom, xyz, op, translation, pt, pt2, id, type, scaleFactor, nth, options, opList){
return this.getDesc(modelSet).getSymopInfo(iatom, xyz, op, translation, pt, pt2, id, type, scaleFactor, nth, options, opList);
}, "JM.ModelSet,~N,~S,~N,JU.P3,JU.P3,JU.P3,~S,~N,~N,~N,~N,~A");
Clazz.overrideMethod(c$, "getSpaceGroupInfo", 
function(modelSet, sgName, modelIndex, isFull, cellParams){
var isForModel = (sgName == null);
if (sgName == null) {
if (modelIndex < 0) modelIndex = modelSet.vwr.am.cmi;
var info = modelSet.getModelAuxiliaryInfo(modelIndex);
if (info != null) sgName = info.get("spaceGroup");
}var cellInfo = null;
if (cellParams != null) {
cellInfo =  new JS.Symmetry().setViewer(this.vwr, "getSGI").setUnitCellFromParams(cellParams, false, NaN);
}return this.getDesc(modelSet).getSpaceGroupInfo(this, modelIndex, sgName, 0, null, null, null, 0, -1, isFull, isForModel, 0, cellInfo, null);
}, "JM.ModelSet,~S,~N,~B,~A");
Clazz.overrideMethod(c$, "getV0abc", 
function(def, retMatrix){
var t = null;
{
t = (def && def[0] ? def[0] : null);
}return ((t != null ? Clazz.instanceOf(t,"JU.T3") : Clazz.instanceOf(def,Array)) ? def : JS.UnitCell.getMatrixAndUnitCell(this.vwr, this.unitCell, def, retMatrix));
}, "~O,JU.M4");
Clazz.overrideMethod(c$, "getQuaternionRotation", 
function(abc){
return (this.unitCell == null ? null : this.unitCell.getQuaternionRotation(abc));
}, "~S");
Clazz.overrideMethod(c$, "getFractionalOrigin", 
function(){
return this.unitCell.getFractionalOrigin();
});
Clazz.overrideMethod(c$, "getState", 
function(ms, modelIndex, commands){
var isAssigned = (ms.getInfo(modelIndex, "spaceGroupAssigned") != null);
var pt = this.getFractionalOffset(false);
var loadUC = false;
if (pt != null && (pt.x != 0 || pt.y != 0 || pt.z != 0)) {
commands.append("; set unitcell ").append(JU.Escape.eP(pt));
loadUC = true;
}var ptm = this.getUnitCellMultiplier();
if (ptm != null) {
commands.append("; set unitcell ").append(JU.SimpleUnitCell.escapeMultiplier(ptm));
loadUC = true;
}var sg = ms.getInfo(modelIndex, "spaceGroup");
if (isAssigned && sg != null) {
var ipt = sg.indexOf("#");
if (ipt >= 0) sg = sg.substring(ipt + 1);
var cmd = "\n UNITCELL " + JU.Escape.e(ms.getUnitCell(modelIndex).getUnitCellVectors());
commands.append(cmd);
commands.append("\n MODELKIT SPACEGROUP " + JU.PT.esc(sg));
commands.append(cmd);
loadUC = true;
}var spinRot = ms.getInfo(modelIndex, "spinRotationMatrixApplied");
if (spinRot != null) {
commands.append("\n rotate VECTORS " + JU.Quat.newM(spinRot));
loadUC = true;
}return loadUC;
}, "JM.ModelSet,~N,JU.SB");
Clazz.overrideMethod(c$, "getIterator", 
function(vwr, atom, bsAtoms, radius){
return (J.api.Interface.getInterface("JS.UnitCellIterator", vwr, "script")).set(this, atom, vwr.ms.at, bsAtoms, radius);
}, "JV.Viewer,JM.Atom,JU.BS,~N");
Clazz.overrideMethod(c$, "toFromPrimitive", 
function(toPrimitive, type, oabc, primitiveToCrystal){
if (this.unitCell == null) this.unitCell = JS.UnitCell.fromOABC(oabc, false);
return this.unitCell.toFromPrimitive(toPrimitive, type, oabc, primitiveToCrystal);
}, "~B,~S,~A,JU.M3");
Clazz.overrideMethod(c$, "generateCrystalClass", 
function(pt00){
if (this.symmetryInfo == null || !this.symmetryInfo.isCurrentCell) return null;
var ops = this.getSymmetryOperations();
var lst =  new JU.Lst();
var isRandom = (pt00 == null);
var rand1 = 0;
var rand2 = 0;
var rand3 = 0;
var pt0;
if (isRandom) {
rand1 = 2.718281828459045;
rand2 = 3.141592653589793;
rand3 = Math.log10(2000);
pt0 = JU.P3.new3(rand1 + 1, rand2 + 2, rand3 + 3);
} else {
pt0 = JU.P3.newP(pt00);
}if (ops == null || this.unitCell == null) {
lst.addLast(pt0);
} else {
this.unitCell.toFractional(pt0, true);
var pt1 = null;
var pt2 = null;
if (isRandom) {
pt1 = JU.P3.new3(rand2 + 4, rand3 + 5, rand1 + 6);
this.unitCell.toFractional(pt1, true);
pt2 = JU.P3.new3(rand3 + 7, rand1 + 8, rand2 + 9);
this.unitCell.toFractional(pt2, true);
}var bspt =  new J.bspt.Bspt(3, 0);
var iter = bspt.allocateCubeIterator();
var pt =  new JU.P3();
out : for (var i = ops.length; --i >= 0; ) {
ops[i].rotate2(pt0, pt);
iter.initialize(pt, 0.001, false);
if (iter.hasMoreElements()) continue out;
var ptNew = JU.P3.newP(pt);
lst.addLast(ptNew);
bspt.addTuple(ptNew);
if (isRandom) {
if (pt2 != null) {
ops[i].rotate2(pt2, pt);
lst.addLast(JU.P3.newP(pt));
}if (pt1 != null) {
ops[i].rotate2(pt1, pt);
lst.addLast(JU.P3.newP(pt));
}}}
for (var j = lst.size(); --j >= 0; ) {
pt = lst.get(j);
if (isRandom) pt.scale(0.5);
this.unitCell.toCartesian(pt, true);
}
}return lst;
}, "JU.P3");
Clazz.overrideMethod(c$, "calculateCIPChiralityForAtoms", 
function(vwr, bsAtoms){
vwr.setCursor(3);
var cip = this.getCIPChirality(vwr);
var dataClass = (vwr.getBoolean(603979960) ? "CIPData" : "CIPDataTracker");
var data = (J.api.Interface.getInterface("JS." + dataClass, vwr, "script")).set(vwr, bsAtoms);
data.setRule6Full(vwr.getBoolean(603979823));
cip.getChiralityForAtoms(data);
vwr.setCursor(0);
}, "JV.Viewer,JU.BS");
Clazz.overrideMethod(c$, "calculateCIPChiralityForSmiles", 
function(smiles){
this.vwr.setCursor(3);
var cip = this.getCIPChirality(this.vwr);
var data = (J.api.Interface.getInterface("JS.CIPDataSmiles", this.vwr, "script")).setAtomsForSmiles(this.vwr, smiles);
cip.getChiralityForAtoms(data);
this.vwr.setCursor(0);
return data.getSmilesChiralityArray();
}, "~S");
Clazz.defineMethod(c$, "getCIPChirality", 
function(vwr){
return (this.cip == null ? (this.cip = (J.api.Interface.getInterface("JS.CIPChirality", vwr, "script"))) : this.cip);
}, "JV.Viewer");
Clazz.overrideMethod(c$, "getUnitCellInfoMap", 
function(){
return (this.unitCell == null ? null : this.unitCell.getInfo());
});
Clazz.overrideMethod(c$, "setUnitCell", 
function(uc){
this.unitCell = JS.UnitCell.cloneUnitCell((uc).unitCell);
}, "J.api.SymmetryInterface");
Clazz.overrideMethod(c$, "findSpaceGroup", 
function(atoms, xyzList, unitCellParams, origin, oabc, flags){
return (J.api.Interface.getInterface("JS.SpaceGroupFinder", this.vwr, "eval")).findSpaceGroup(this.vwr, atoms, xyzList, unitCellParams, origin, oabc, this, flags);
}, "JU.BS,~S,~A,JU.T3,~A,~N");
Clazz.overrideMethod(c$, "setSpaceGroupName", 
function(name){
this.clearSymmetryInfo();
if (this.spaceGroup != null) this.spaceGroup.setName(name);
}, "~S");
Clazz.overrideMethod(c$, "setSpaceGroupTo", 
function(sg){
if (Clazz.instanceOf(sg,"JS.Symmetry")) {
this.spaceGroup = (sg).spaceGroup;
this.symmetryInfo = (sg).symmetryInfo;
if (this.spaceGroup == null && this.symmetryInfo != null) this.spaceGroup = this.symmetryInfo.fileSpaceGroup;
} else if (Clazz.instanceOf(sg,"JS.SpaceGroup")) {
this.spaceGroup = sg;
} else {
this.spaceGroup = JS.SpaceGroup.getSpaceGroupFromJmolClegOrITA(this.vwr, sg.toString());
}this.clearSymmetryInfo();
}, "~O");
Clazz.defineMethod(c$, "clearSymmetryInfo", 
function(){
if (this.symmetryInfo != null) {
this.symmetryInfo = null;
}});
Clazz.overrideMethod(c$, "removeDuplicates", 
function(ms, bs, highPrec){
var uc = this.unitCell;
var atoms = ms.at;
var occs = ms.occupancies;
var haveOccupancies = (occs != null);
var unitized =  new Array(bs.length());
for (var i = bs.nextSetBit(0); i >= 0; i = bs.nextSetBit(i + 1)) {
var pt = unitized[i] = JU.P3.newP(atoms[i]);
uc.toFractional(pt, false);
if (highPrec) uc.unitizeRnd(pt);
 else uc.unitize(pt);
}
for (var i = bs.nextSetBit(0); i >= 0; i = bs.nextSetBit(i + 1)) {
var a = atoms[i];
var pt = unitized[i];
var type = a.getAtomicAndIsotopeNumber();
var occ = (haveOccupancies ? occs[i] : 0);
for (var j = bs.nextSetBit(i + 1); j >= 0; j = bs.nextSetBit(j + 1)) {
var b = atoms[j];
if (type != b.getAtomicAndIsotopeNumber() || (haveOccupancies && occ != occs[j])) continue;
var pt2 = unitized[j];
if (pt.distanceSquared(pt2) < 1.96E-6) {
bs.clear(j);
}}
}
return bs;
}, "JM.ModelSet,JU.BS,~B");
Clazz.overrideMethod(c$, "getPeriodicity", 
function(){
return (this.spaceGroup == null ? 0x7 : this.spaceGroup.periodicity);
});
Clazz.overrideMethod(c$, "getDimensionality", 
function(){
return (this.spaceGroup == null ? 3 : this.spaceGroup.nDim);
});
Clazz.overrideMethod(c$, "getGroupType", 
function(){
return (this.spaceGroup == null ? 0 : this.spaceGroup.groupType);
});
Clazz.overrideMethod(c$, "getEquivPoints", 
function(pt, flags, packing){
var ops = this.getSymmetryOperations();
return (ops == null || this.unitCell == null ? null : this.unitCell.getEquivalentPoints(pt, flags, ops,  new JU.Lst(), 0, 0, 0, this.getPeriodicity(), packing));
}, "JU.Point3fi,~S,~N");
Clazz.overrideMethod(c$, "getEquivPointList", 
function(nInitial, flags, opsCtr, packing, pts, uc){
var ops = (opsCtr == null ? this.getSymmetryOperations() : opsCtr);
var newPt = (flags.indexOf("newpt") >= 0);
var zapped = (flags.indexOf("zapped") >= 0);
var n = pts.size();
var tofractional = (flags.indexOf("tofractional") >= 0);
if (flags.indexOf("fromfractional") < 0) {
for (var i = 0; i < pts.size(); i++) {
this.toFractional(pts.get(i), false);
}
}flags += ",fromfractional,tofractional";
var check0 = (nInitial > 0 ? 0 : n);
var allPoints = (nInitial == n);
var n0 = (nInitial > 0 ? nInitial : n);
if (allPoints) {
nInitial--;
n0--;
}if (zapped) n0 = 0;
var p0 = (nInitial > 0 ? pts.get(nInitial) : null);
var dup0 = (opsCtr == null ? n0 : check0);
if (ops != null || this.unitCell != null) {
var per = this.getPeriodicity();
for (var i = nInitial; i < n; i++) {
this.unitCell.getEquivalentPoints(pts.get(i), flags, ops, pts, check0, n0, dup0, per, packing);
}
}if (!zapped && (pts.size() == nInitial || pts.get(nInitial) !== p0 || allPoints || newPt)) n--;
for (var i = n - nInitial; --i >= 0; ) pts.removeItemAt(nInitial);

if (!tofractional) {
var pt = (uc == null ? null :  new JU.P3());
for (var i = pts.size(); --i >= nInitial; ) {
var p = pts.get(i);
this.toCartesian(p, false);
if (uc != null) {
uc.toFractional(pt.setP(p), false);
if (!uc.isWithinUnitCell(pt, 1, 1, 1, packing)) {
pts.removeItemAt(i);
continue;
}}}
}}, "~N,~S,~A,~N,JU.Lst,J.api.SymmetryInterface");
Clazz.overrideMethod(c$, "adjustRangeMinMax", 
function(oabc, packingRange, minXYZ, maxXYZ, rmin, rmax, newMin, newMax){
this.unitCell.adjustRangeMinMax(oabc, packingRange, minXYZ, maxXYZ, rmin, rmax, newMin, newMax);
}, "~A,~N,JU.P3i,JU.P3i,JU.P3,JU.P3,JU.P3i,JU.P3i");
Clazz.overrideMethod(c$, "getInvariantSymops", 
function(pt, v0){
var ops = this.getSymmetryOperations();
if (ops == null) return  Clazz.newIntArray (0, 0);
var bs =  new JU.BS();
var p =  new JU.P3();
var p0 =  new JU.P3();
var nops = ops.length;
for (var i = 1; i < nops; i++) {
p.setT(pt);
this.unitCell.toFractional(p, false);
this.unitCell.unitize(p);
p0.setT(p);
ops[i].rotTrans(p);
this.unitCell.unitize(p);
if (p0.distanceSquared(p) < 1.96E-6) {
bs.set(i);
}}
var ret =  Clazz.newIntArray (bs.cardinality(), 0);
if (v0 != null && ret.length != v0.length) return null;
for (var k = 0, i = 1; i < nops; i++) {
var isOK = bs.get(i);
if (isOK) {
if (v0 != null && v0[k] != i + 1) return null;
ret[k++] = i + 1;
}}
return ret;
}, "JU.P3,~A");
Clazz.overrideMethod(c$, "getWyckoffPosition", 
function(p, letter){
if (this.unitCell == null) return "";
var sg = this.spaceGroup;
if (sg == null && this.symmetryInfo != null) {
sg = JS.SpaceGroup.determineSpaceGroupN(this.symmetryInfo.sgName);
if (sg == null) {
var id = this.getSpaceGroupJmolId();
if (id == null) id = this.getSpaceGroupClegId();
sg = JS.SpaceGroup.getSpaceGroupFromJmolClegOrITA(this.vwr, id);
}}if (sg == null || sg.itaNumber == null) {
return "?";
}if (p == null) {
p = JU.P3.new3(0.53, 0.20, 0.16);
} else if (!"L".equals(letter)) {
p = JU.P3.newP(p);
this.unitCell.toFractional(p, false);
this.unitCell.unitize(p);
}try {
var w = this.getWyckoffFinder().getWyckoffFinder(this.vwr, sg);
var withMult = (letter != null && letter.charAt(0) == 'M');
if (withMult) {
letter = (letter.length == 1 ? null : letter.substring(1));
}var mode = (letter == null ? -1 : "L".equals(letter) ? -4 : letter.equalsIgnoreCase("coord") ? -2 : letter.equalsIgnoreCase("coords") ? -3 : letter.endsWith("*") ? (letter.charAt(0)).charCodeAt(0) : 0);
if (mode != 0) {
return (w == null ? "?" : w.getInfo(this.unitCell, p, mode, withMult, this.vwr.is2D()));
}if (w.findPositionFor(p, letter) == null) return null;
this.unitCell.toCartesian(p, false);
return p;
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
e.printStackTrace();
return (letter == null ? "?" : null);
} else {
throw e;
}
}
}, "JU.P3,~S");
Clazz.defineMethod(c$, "getWyckoffFinder", 
function(){
if (JS.Symmetry.wyckoffFinder == null) {
JS.Symmetry.wyckoffFinder = J.api.Interface.getInterface("JS.WyckoffFinder", null, "symmetry");
}return JS.Symmetry.wyckoffFinder;
});
Clazz.overrideMethod(c$, "getTransform", 
function(fracA, fracB, best){
return this.getDesc(null).getTransform(this.unitCell, this.getSymmetryOperations(), fracA, fracB, best);
}, "JU.P3,JU.P3,~B");
Clazz.defineMethod(c$, "isWithinUnitCell", 
function(pt, x, y, z, packing){
return this.unitCell.isWithinUnitCell(x, y, z, packing, pt);
}, "JU.P3,~N,~N,~N,~N");
Clazz.overrideMethod(c$, "checkPeriodic", 
function(pt, packing, packing2){
return this.unitCell.checkPeriodic(pt, packing, packing2);
}, "JU.P3,~N,~N");
Clazz.overrideMethod(c$, "staticConvertOperation", 
function(xyz, matrix, labels){
return JS.SymmetryOperation.staticConvertOperation(xyz, matrix, labels);
}, "~S,JU.M34,~S");
Clazz.overrideMethod(c$, "getSubgroupJSON", 
function(nameFrom, nameTo, i1, i2, flags, retMap, retLst){
if (nameFrom.startsWith("ITA/")) nameFrom = nameFrom.substring(4);
if (nameTo != null && nameTo.startsWith("ITA/")) nameTo = nameTo.substring(4);
var groupType1 = JS.SpaceGroup.getExplicitSpecialGroupType(nameFrom);
if (groupType1 == -1) return null;
var groupType2 = (nameTo == null || nameTo.length == 0 ? groupType1 : JS.SpaceGroup.getExplicitSpecialGroupType(nameFrom));
if (groupType2 != groupType1) return null;
var sgNameFrom = (groupType1 == 0 ? nameFrom : nameFrom.substring(2));
if (sgNameFrom.equalsIgnoreCase("all")) {
return this.getAllITSubData(groupType1);
}var itaFrom = JU.PT.parseInt(this.getSpaceGroupInfoObj("itaNumber", sgNameFrom, false, false));
var itaTo = (nameTo == null ? -1 : nameTo.length == 0 ? 0 : JU.PT.parseInt(this.getSpaceGroupInfoObj("itaNumber", groupType1 == 0 ? nameTo : nameTo.substring(2), false, false)));
if (flags != 0) {
var prefix = JS.SpaceGroup.getGroupTypePrefix(groupType1);
var indexMax = (flags >> 24) & 0xFF;
var indexMin = (flags >> 16) & 0xFF;
var depthMax = (flags >> 8) & 0xFF;
var depthMin = flags & 0xFF;
var data = this.getSubgroupIndexData(groupType1);
var lstAll = (retLst == null ? null :  new JU.Lst());
var stack =  new JU.Lst();
var indexPath = this.findSubTransform(itaFrom, itaTo, indexMax, indexMin, depthMax, depthMin, data, 1, 1, 1, JU.BSUtil.newAndSetBit(itaFrom), stack, lstAll);
if (indexPath == null ? lstAll == null : indexPath.endsWith("!")) return indexPath;
var trm = indexPath;
for (var ilist = 0, n = (lstAll == null ? 1 : lstAll.size()); ilist < n; ilist++) {
var ret = (retMap == null ?  new java.util.Hashtable() : retMap);
if (retLst != null && ret !== retMap) retLst.addLast(ret);
if (lstAll != null) indexPath = lstAll.get(ilist);
var tokens = indexPath.$plit(">");
var nt = tokens.length;
var hmCleg =  new Array(nt);
var cleg = "";
var bcsPath = "";
var index = 1;
var depth = 0;
for (var i = nt - 3; i >= 0; i -= 2) {
depth++;
var g1 = prefix + tokens[i];
var g2 = tokens[i + 2] = prefix + tokens[i + 2];
index *= Integer.parseInt(tokens[i + 1].substring(1, tokens[i + 1].length - 1));
trm = tokens[i + 1] = this.getSubgroupJSON(g1, g2, 0, 1, 0, null, null);
cleg += ">" + trm;
hmCleg[i] = this.getSpaceGroupInfoObj("hmNameShort", g1, false, false);
hmCleg[i + 1] = "";
if (i == nt - 3) hmCleg[i + 2] = this.getSpaceGroupInfoObj("hmNameShort", g2, false, false);
}
tokens[0] = prefix + tokens[0];
for (var i = 0; i < nt; i += 2) {
bcsPath += ">" + hmCleg[i];
}
bcsPath = bcsPath.substring(1);
var m = this.convertTransform(cleg.substring(1), null);
ret.put("trm", this.convertTransform(null, m));
ret.put("trmat", m);
ret.put("index", Integer.$valueOf(index));
ret.put("depth", Integer.$valueOf(depth));
ret.put("indexPath", indexPath);
ret.put("cleg", JU.PT.join(tokens, '>', 0));
ret.put("bcsPath", JU.PT.rep(bcsPath, " ", ""));
}
return trm;
}var allSubsMap = (itaTo < 0);
var asIntArray = (itaTo == 0 && i1 == 0);
var asSSIntArray = (itaTo == 0 && i1 < 0);
var isIndexMap = (itaTo == 0 && i1 > 0 && i2 < 0);
var isIndexTStr = (itaTo == 0 && i1 > 0 && i2 > 0);
var isWhereList = (itaTo > 0 && i1 < 0);
var isWhereMap = (itaTo > 0 && i1 > 0 && i2 < 0);
var isWhereTStr = (itaTo > 0 && i1 > 0 && i2 > 0);
try {
var o = this.getSpaceGroupJSON("subgroups", nameFrom, itaFrom);
var ithis = 0;
while (true) {
if (o == null) break;
if (allSubsMap) return o;
if (asIntArray || asSSIntArray) {
var list = o.get("subgroups");
var n = list.size();
var groups = (asIntArray ? JU.AU.newInt2(n) : null);
var bs = (asSSIntArray ?  new JU.BS() : null);
for (var i = n; --i >= 0; ) {
o = list.get(i);
var isub = (o.get("subgroup")).intValue();
if (asSSIntArray) {
bs.set(isub);
continue;
}var subIndex = (o.get("subgroupIndex")).intValue();
var trType = "k".equals(o.get("trType")) ? 2 : 1;
var subType = (trType == 1 ? o.get("trSubtype") : "");
var det = (o.get("det")).doubleValue();
var idet = Clazz.doubleToInt(det < 1 ? -1 / det : det);
if (subType.equals("ct")) trType = 3;
 else if (subType.equals("eu")) trType = 4;
var ntrm = (o.get("trm")).size();
groups[i] =  Clazz.newIntArray(-1, [isub, ntrm, subIndex, idet, trType]);
}
if (asSSIntArray) {
var a =  Clazz.newIntArray (bs.cardinality(), 0);
for (var p = 0, i = bs.nextSetBit(0); i >= 0; i = bs.nextSetBit(i + 1)) {
a[p++] = i;
}
return a;
}return groups;
}var list = o.get("subgroups");
var i0 = 0;
var n = list.size();
if (isIndexMap || isIndexTStr) {
if (i1 > n) {
throw  new ArrayIndexOutOfBoundsException("no map.subgroups[" + i1 + "]!");
}i0 = i1 - 1;
if (isIndexMap) return list.get(i0);
n = i1;
}var whereList = (isWhereList ?  new JU.Lst() : null);
for (var i = i0; i < n; i++) {
o = list.get(i);
var isub = (o.get("sg")).intValue();
if (!isIndexTStr && isub != itaTo) continue;
if (++ithis == i1) {
if (isWhereMap) return o;
} else if (isWhereTStr) {
continue;
}if (isWhereList) {
whereList.addLast(o);
continue;
}var trms = o.get("trms");
n = trms.size();
if (i2 < 1 || i2 > n) return null;
var m = trms.get(i2 - 1);
return m.get("trm");
}
if (isWhereList && !whereList.isEmpty()) {
return whereList;
}break;
}
if (i1 == 0) return null;
if (isWhereTStr && ithis > 0) {
throw  new ArrayIndexOutOfBoundsException("only " + ithis + " maximal subgroup information for " + itaFrom + ">>" + itaTo + "!");
}throw  new ArrayIndexOutOfBoundsException("no subgroup information for " + itaFrom + ">>" + itaTo + "!");
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
return e.getMessage();
} else {
throw e;
}
}
}, "~S,~S,~N,~N,~N,java.util.Map,JU.Lst");
Clazz.defineMethod(c$, "findSubTransform", 
function(itaFrom, itaTo, indexMax, indexMin, depthMax, depthMin, data, depth, indexLast, index0, bs, stack, retAll){
var fromData = data[itaFrom];
if (depthMax > 0 && depth > depthMax) return null;
var isFirstA2A = (itaFrom == itaTo && depth == 1);
var i2 = (isFirstA2A ? 2 : 3);
for (var step = (depth > 0 && depth < depthMin ? 2 : 1); step < i2; step++) {
out : for (var i = 0, n = fromData.length; i < n; i++) {
var group = fromData[i][0];
if (bs.get(group) && !isFirstA2A) continue;
var index = fromData[i][1];
var indexNew = index * index0;
if (indexNew > indexMax) continue;
switch (step) {
case 1:
if (group == itaTo) {
if (indexNew < indexMin) continue;
var s = "";
for (var is = 0, ns = stack.size(); is < ns; is++) {
var gi = stack.get(is);
s += gi[0] + ">[" + gi[1] + "]>";
}
s += itaFrom + ">[" + index + "]>" + group;
if (retAll != null) {
retAll.addLast(s);
break out;
}return s;
}break;
case 2:
if (group != itaTo && !bs.get(group)) {
var bsNew = JU.BSUtil.copy(bs);
bsNew.set(group);
var last = stack.size();
stack.addLast( Clazz.newIntArray(-1, [itaFrom, index]));
var s = this.findSubTransform(group, itaTo, indexMax, indexMin, depthMax, depthMin, data, depth + 1, index, indexNew, bsNew, stack, retAll);
stack.removeItemAt(last);
if (s != null && retAll == null) return s;
}break;
}
}
}
return null;
}, "~N,~N,~N,~N,~N,~N,~A,~N,~N,~N,JU.BS,JU.Lst,JU.Lst");
Clazz.overrideMethod(c$, "getSpaceGroupJSON", 
function(name, data, index){
var index0 = index;
var isSetting = name.equals("setting");
var isSettings = name.equals("settings");
var isAFLOW = name.equalsIgnoreCase("AFLOWLIB");
var isSubgroups = !isSettings && name.equals("subgroups");
var isThis = ((isSetting || isSettings || isSubgroups) && index == -2147483648);
var s0 = (!isSettings && !isSetting && !isSubgroups ? name : isThis ? this.getSpaceGroupName() : "" + index);
try {
var itno;
var specialType = (data == null ? 0 : JS.SpaceGroup.getExplicitSpecialGroupType(data));
if (specialType > 0) data = data.substring(2);
var tm = null;
var isTM;
var isInt;
var sgname;
if (isSetting && data == null || isSettings || isSubgroups) {
isTM = false;
isInt = true;
sgname = (isSetting ? data : null);
if (isThis) {
itno = JU.PT.parseInt(this.getIntTableNumber());
if (isSetting || isSettings) {
if (this.spaceGroup == null) {
var sg = this.symmetryInfo.getDerivedSpaceGroup();
if (sg == null) return  new java.util.Hashtable();
sgname = sg.jmolId;
} else {
sgname = this.getSpaceGroupClegId();
if (isSetting) {
tm = sgname.substring(sgname.indexOf(":") + 1);
} else if (isSettings) {
index = 0;
}}}} else {
itno = index;
}} else {
if (!isAFLOW) index = 0;
sgname = data;
var pt = sgname.indexOf("(");
if (pt < 0) pt = sgname.indexOf(":");
isTM = (pt >= 0 && sgname.indexOf(",") > pt);
if (isTM) {
tm = sgname.substring(pt + 1, sgname.length - (sgname.endsWith(")") ? 1 : 0));
sgname = sgname.substring(0, pt);
isThis = true;
}itno = (sgname.equalsIgnoreCase("ALL") ? 0 : JU.PT.parseInt(sgname));
isInt = (itno != -2147483648);
pt = sgname.indexOf('.');
if (!isTM && isInt && index == 0 && pt > 0) {
index = JU.PT.parseInt(sgname.substring(pt + 1));
sgname = sgname.substring(0, pt);
}}if (isInt && (itno > JS.SpaceGroup.getMax(specialType) || (isSettings || isSetting ? itno < 1 : itno < 0))) throw  new ArrayIndexOutOfBoundsException(itno);
if (isSubgroups) {
var resource = this.getITSubJSONResource(specialType, itno);
if (resource != null) {
return resource;
}} else if (isSetting || isSettings || name.equalsIgnoreCase("ITA")) {
if (itno == 0) {
return JS.Symmetry.getAllITAData(this.vwr, specialType, true);
}var isSpecial = (specialType > 0);
var resource = JS.Symmetry.getITJSONResource(this.vwr, specialType, (itno < 1 ? index0 : itno), data);
if (resource != null) {
if (index == 0 && tm == null) return (isSettings ? resource.get("its") : resource);
var its = resource.get("its");
if (its != null) {
if (isSettings && !isThis) {
return its;
}var n = its.size();
var i0 = (isSetting ? Math.max(index, 1) : isInt && !isThis ? index : n);
if (i0 > n) return null;
if (isSetting) return its.get(i0 - 1);
var map = null;
for (var i = i0; --i >= 0; ) {
map = its.get(i);
if (i == index - 1 || (tm == null ? (isSpecial ? JS.SpaceGroup.hmMatches(map.get("hm"), sgname, specialType) : sgname.equals(map.get("jmolId"))) : tm.equals(map.get("trm")))) {
if (!map.containsKey("more")) {
return map;
}break;
}map = null;
}
if (map != null) {
return JS.SpaceGroup.fillMoreData(this.vwr, map, data, itno, its.get(0));
}}}} else if (isAFLOW && tm == null) {
if (JS.Symmetry.aflowStructures == null) JS.Symmetry.aflowStructures = JS.Symmetry.getResource(this.vwr, "sg/json/aflow_structures.json");
if (itno == 0) return JS.Symmetry.aflowStructures;
if (itno == -2147483648) {
var start = null;
if (sgname.endsWith("*")) {
start =  new JU.Lst();
sgname = sgname.substring(0, sgname.length - 1);
}for (var j = 1; j <= 230; j++) {
var list = JS.Symmetry.aflowStructures.get("" + j);
for (var i = 0, n = list.size(); i < n; i++) {
var id = list.get(i);
if (start != null && id.startsWith(sgname)) {
start.addLast("=aflowlib/" + j + "." + (i + 1) + "\t" + id);
} else if (id.equalsIgnoreCase(sgname)) {
return j + "." + (i + 1);
}}
}
return (start != null && start.size() > 0 ? start : null);
}var adata = JS.Symmetry.aflowStructures.get("" + sgname);
if (index <= adata.size()) {
return (index == 0 ? adata : adata.get(index - 1));
}}if (isThis) return  new java.util.Hashtable();
throw  new IllegalArgumentException(s0);
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
return e.getMessage();
} else {
throw e;
}
}
}, "~S,~S,~N");
c$.getITJSONResource = Clazz.defineMethod(c$, "getITJSONResource", 
function(vwr, type, itno, specialName){
if (type == 0) {
if (JS.Symmetry.itaData == null) JS.Symmetry.itaData =  new Array(230);
var resource = JS.Symmetry.itaData[itno - 1];
if (resource == null) JS.Symmetry.itaData[itno - 1] = resource = JS.Symmetry.getResource(vwr, "sg/json/ita_" + itno + ".json");
return resource;
}var data = JS.Symmetry.getAllITAData(vwr, type, false);
if (itno > 0) return data[itno - 1];
return JS.Symmetry.getSpecialSettingJSON(data, specialName, type, false);
}, "JV.Viewer,~N,~N,~S");
c$.getSpecialSettingJSON = Clazz.defineMethod(c$, "getSpecialSettingJSON", 
function(data, name, specialType, thisSettingOnly){
var info = null;
var isCleg = Character.isDigit(name.charAt(2));
if (isCleg && name.endsWith(";0,0,0")) {
name = name.substring(0, name.length - 6);
}var key = (isCleg ? "clegId" : "hm");
if (!isCleg) name = name.substring(2);
for (var i = data.length; --i >= 0; ) {
var lst = data[i].get("its");
for (var j = lst.size(); --j >= 0; ) {
info = lst.get(j);
var val = info.get(key);
if (isCleg ? name.equals(val) : JS.SpaceGroup.hmMatches(val, name, specialType)) {
return (thisSettingOnly ? info : data[i]);
}}
}
return null;
}, "~A,~S,~N,~B");
c$.getAllITAData = Clazz.defineMethod(c$, "getAllITAData", 
function(vwr, type, isAll){
switch (type) {
case 0:
if (JS.Symmetry.allDataITA == null) JS.Symmetry.allDataITA = JS.Symmetry.getResource(vwr, "sg/json/ita_all.json");
return JS.Symmetry.allDataITA;
default:
var name = "sg/json/it" + (type == 300 ? "a" : "e") + "_all_" + JS.SpaceGroup.getSpecialGroupName(type) + ".json";
switch (type) {
case 300:
if (JS.Symmetry.allPlaneData == null) {
JS.Symmetry.allPlaneData = JS.Symmetry.getResource(vwr, name);
JS.Symmetry.planeData = JS.Symmetry.createSpecialData(type, JS.Symmetry.allPlaneData);
}return (isAll ? JS.Symmetry.allPlaneData : JS.Symmetry.planeData);
case 400:
if (JS.Symmetry.allLayerData == null) {
JS.Symmetry.allLayerData = JS.Symmetry.getResource(vwr, name);
JS.Symmetry.layerData = JS.Symmetry.createSpecialData(type, JS.Symmetry.allLayerData);
}return (isAll ? JS.Symmetry.allLayerData : JS.Symmetry.layerData);
case 500:
if (JS.Symmetry.allRodData == null) {
JS.Symmetry.allRodData = JS.Symmetry.getResource(vwr, name);
JS.Symmetry.rodData = JS.Symmetry.createSpecialData(type, JS.Symmetry.allRodData);
}return (isAll ? JS.Symmetry.allRodData : JS.Symmetry.rodData);
case 600:
if (JS.Symmetry.allFriezeData == null) {
JS.Symmetry.allFriezeData = JS.Symmetry.getResource(vwr, name);
JS.Symmetry.friezeData = JS.Symmetry.createSpecialData(type, JS.Symmetry.allFriezeData);
}return (isAll ? JS.Symmetry.allFriezeData : JS.Symmetry.friezeData);
}
}
return null;
}, "JV.Viewer,~N,~B");
c$.createSpecialData = Clazz.defineMethod(c$, "createSpecialData", 
function(type, data){
var n = JS.SpaceGroup.getMax(type);
var list =  new Array(n);
for (var i = 0; i < n; i++) {
list[i] =  new java.util.Hashtable();
list[i].put("sg", Integer.$valueOf(i + 1));
list[i].put("its",  new JU.Lst());
}
for (var i = 0, nd = data.size(); i < nd; i++) {
var map = data.get(i);
var sg = (map.get("sg")).intValue();
(list[sg - 1].get("its")).addLast(map);
}
for (var i = 0; i < n; i++) {
list[i].put("n", Integer.$valueOf((list[i].get("its")).size()));
}
return list;
}, "~N,JU.Lst");
Clazz.defineMethod(c$, "getAllITSubData", 
function(type){
switch (type) {
default:
case 0:
return null;
case 300:
if (JS.Symmetry.planeSubData == null) JS.Symmetry.planeSubData = JS.Symmetry.getResource(this.vwr, "sg/json/sub_all_plane.json");
return JS.Symmetry.planeSubData;
case 400:
if (JS.Symmetry.layerSubData == null) JS.Symmetry.layerSubData = JS.Symmetry.getResource(this.vwr, "sg/json/sub_all_layer.json");
return JS.Symmetry.layerSubData;
case 500:
if (JS.Symmetry.rodSubData == null) JS.Symmetry.rodSubData = JS.Symmetry.getResource(this.vwr, "sg/json/sub_all_rod.json");
return JS.Symmetry.rodSubData;
case 600:
if (JS.Symmetry.friezeSubData == null) JS.Symmetry.friezeSubData = JS.Symmetry.getResource(this.vwr, "sg/json/sub_all_frieze.json");
return JS.Symmetry.friezeSubData;
}
}, "~N");
Clazz.defineMethod(c$, "getITSubJSONResource", 
function(type, itno){
if (type == 0) {
if (JS.Symmetry.itaSubData == null) JS.Symmetry.itaSubData =  new Array(230);
var resource = JS.Symmetry.itaSubData[itno - 1];
if (resource == null) JS.Symmetry.itaSubData[itno - 1] = resource = JS.Symmetry.getResource(this.vwr, "sg/json/sub_" + itno + ".json");
return resource;
}return this.getAllITSubData(type).get(itno - 1);
}, "~N,~N");
Clazz.defineMethod(c$, "getSubgroupIndexData", 
function(groupType){
var typeName = JS.SpaceGroup.getSpecialGroupName(groupType);
var nGroups = JS.SpaceGroup.getMax(groupType);
var data;
switch (groupType) {
default:
case 0:
if (JS.Symmetry.itaSubList == null) JS.Symmetry.itaSubList = JU.AU.newInt3(nGroups + 1, 0);
data = JS.Symmetry.itaSubList;
break;
case 300:
if (JS.Symmetry.planeSubList == null) JS.Symmetry.planeSubList = JU.AU.newInt3(nGroups + 1, 0);
data = JS.Symmetry.planeSubList;
break;
case 400:
if (JS.Symmetry.layerSubList == null) JS.Symmetry.layerSubList = JU.AU.newInt3(nGroups + 1, 0);
data = JS.Symmetry.layerSubList;
break;
case 500:
if (JS.Symmetry.rodSubList == null) JS.Symmetry.rodSubList = JU.AU.newInt3(nGroups + 1, 0);
data = JS.Symmetry.rodSubList;
break;
case 600:
if (JS.Symmetry.friezeSubList == null) JS.Symmetry.friezeSubList = JU.AU.newInt3(nGroups + 1, 0);
data = JS.Symmetry.friezeSubList;
break;
}
var o = JS.Symmetry.getResource(this.vwr, "sg/json/sub_" + (groupType == 0 ? "" : typeName + "_") + "index.json");
for (var i = o.size(); --i >= 0; ) {
var l = o.get(i);
var n = Clazz.doubleToInt(l.size() / 2);
var a = data[i + 1] = JU.AU.newInt2(n);
for (var j = 0, pt = 0; j < n; j++) {
a[j] =  Clazz.newIntArray(-1, [(l.get(pt++)).intValue(), (l.get(pt++)).intValue()]);
}
}
return data;
}, "~N");
c$.getResource = Clazz.defineMethod(c$, "getResource", 
function(vwr, resource){
try {
var r = JV.FileManager.getBufferedReaderForResource(vwr, JS.Symmetry, "JS/", resource);
var data =  new Array(1);
if (JU.Rdr.readAllAsString(r, 2147483647, false, data, 0)) {
return  new JU.JSJSONParser().parse(data[0], true);
}} catch (e) {
System.err.println(e.getMessage());
}
return null;
}, "JV.Viewer,~S");
Clazz.overrideMethod(c$, "getCellWeight", 
function(pt){
return this.unitCell.getCellWeight(pt);
}, "JU.P3");
Clazz.overrideMethod(c$, "getPrecision", 
function(){
return (this.unitCell == null ? NaN : this.unitCell.getPrecision());
});
Clazz.overrideMethod(c$, "fixUnitCell", 
function(params){
return this.spaceGroup.createCompatibleUnitCell(params, null, true);
}, "~A");
Clazz.overrideMethod(c$, "staticGetTransformABC", 
function(transform, normalize){
return JS.SymmetryOperation.getTransformABC(transform, normalize);
}, "JU.M4,~B");
Clazz.defineMethod(c$, "setCartesianOffset", 
function(origin){
this.unitCell.setCartesianOffset(origin);
}, "JU.T3");
Clazz.defineMethod(c$, "setSymmetryInfoFromFile", 
function(ms, modelIndex, unitCellParams){
var info = ms.getModelAuxiliaryInfo(modelIndex);
var fileSymmetry = info.get("fileSymmetry");
this.symmetryInfo =  new JS.SymmetryInfo(fileSymmetry == null ? null : fileSymmetry.spaceGroup);
var params = this.symmetryInfo.setSymmetryInfoFromFile(info, unitCellParams);
if (params == null) return;
this.setUnitCellFromParams(params, info.containsKey("jmolData"), this.symmetryInfo.slop);
this.unitCell.spinFrameToCartXYZ = (fileSymmetry == null ? null : fileSymmetry.spinFrameToCartXYZ);
this.setSpinSym();
this.unitCell.setMoreInfo(info.get("moreUnitCellInfo"));
info.put("infoUnitCell", this.getUnitCellAsArray(false));
this.setOffsetPt(info.get("unitCellOffset"));
var matUnitCellOrientation = info.get("matUnitCellOrientation");
if (matUnitCellOrientation != null) this.initializeOrientation(matUnitCellOrientation);
var s = this.symmetryInfo.strSUPERCELL;
if (s != null) {
var oabc = this.unitCell.getUnitCellVectors();
oabc[0] =  new JU.P3();
ms.setModelCagePts(modelIndex, oabc, "conventional");
}if (JU.Logger.debugging) JU.Logger.debug("symmetryInfos[" + modelIndex + "]:\n" + this.unitCell.dumpInfo(true, true));
}, "JM.ModelSet,~N,~A");
Clazz.defineMethod(c$, "transformUnitCell", 
function(trm){
if (trm == null) {
trm = JS.UnitCell.toTrm(this.spaceGroup.itaTransform, null);
}var trmInv = JU.M4.newM4(trm);
trmInv.invert();
var oabc = this.getUnitCellVectors();
for (var i = 1; i <= 3; i++) {
this.toFractional(oabc[i], true);
trmInv.rotate(oabc[i]);
this.toCartesian(oabc[i], true);
}
var o =  new JU.P3();
trm.getTranslation(o);
this.toCartesian(o, true);
oabc[0].add(o);
this.unitCell = JS.UnitCell.fromOABC(oabc, false);
}, "JU.M4");
Clazz.overrideMethod(c$, "staticCleanTransform", 
function(tr){
return JS.SymmetryOperation.getTransformABC(JS.UnitCell.toTrm(tr, null), true);
}, "~S");
Clazz.overrideMethod(c$, "saveOrRetrieveTransformMatrix", 
function(trm){
var trm0 = this.transformMatrix;
this.transformMatrix = trm;
return trm0;
}, "JU.M4");
Clazz.overrideMethod(c$, "getUnitCellDisplayName", 
function(){
var name = (this.spaceGroup != null ? this.spaceGroup.getDisplayName() : this.symmetryInfo != null ? this.symmetryInfo.getDisplayName(this) : null);
return (name.length > 0 ? name : null);
});
Clazz.overrideMethod(c$, "staticToRationalXYZ", 
function(fPt, sep){
var s = JS.SymmetryOperation.fcoord(fPt, sep);
return (",".equals(sep) ? s : "(" + s + ")");
}, "JU.P3,~S");
Clazz.overrideMethod(c$, "getFinalOperationCount", 
function(){
this.setFinalOperations(3, null, null, -1, -1, false, null);
return this.spaceGroup.getOperationCount();
});
Clazz.overrideMethod(c$, "convertTransform", 
function(transform, trm){
if (transform == null) {
return this.staticGetTransformABC(trm, false);
}if (transform.equals("xyz")) {
return (trm == null ? null : JS.SymmetryOperation.getXYZFromMatrix(trm, false, false, false));
}if (trm == null) trm =  new JU.M4();
JS.UnitCell.getMatrixAndUnitCell(this.vwr, (transform.indexOf("*") >= 0 ? this.unitCell : null), transform, trm);
return trm;
}, "~S,JU.M4");
Clazz.overrideMethod(c$, "staticGetMatrixTransform", 
function(cleg, retLstOrMap){
return this.getCLEGInstance().getMatrixTransform(this.vwr, cleg, retLstOrMap);
}, "~S,~O");
Clazz.overrideMethod(c$, "staticTransformSpaceGroup", 
function(bs, cleg, paramsOrUC, sb){
return this.getCLEGInstance().transformSpaceGroup(this.vwr, bs, cleg, paramsOrUC, sb);
}, "JU.BS,~S,~O,JU.SB");
Clazz.defineMethod(c$, "getCLEGInstance", 
function(){
if (JS.Symmetry.clegInstance == null) {
JS.Symmetry.clegInstance = J.api.Interface.getInterface("JS.CLEG", null, "symmetry");
}return JS.Symmetry.clegInstance;
});
Clazz.overrideMethod(c$, "setViewer", 
function(vwr, id){
if (this.vwr == null) {
this.vwr = vwr;
this.id = id;
}return this;
}, "JV.Viewer,~S");
Clazz.overrideMethod(c$, "getUnitCellCenter", 
function(){
return this.unitCell.getCenter(this.getPeriodicity());
});
c$.getSGFactory = Clazz.defineMethod(c$, "getSGFactory", 
function(){
if (JS.Symmetry.groupFactory == null) {
JS.Symmetry.groupFactory = J.api.Interface.getInterface("JS.SpecialGroupFactory", null, "symmetry");
}return JS.Symmetry.groupFactory;
});
c$.getSpecialSettingInfo = Clazz.defineMethod(c$, "getSpecialSettingInfo", 
function(vwr, name, type){
var s = name.substring(2);
var ptCleg = s.indexOf(":");
var ptTrm = s.indexOf(",");
var ptIndex = s.indexOf(".");
var pt = (ptCleg > 0 && ptTrm > ptCleg ? ptCleg : ptIndex);
var itno = JS.SpaceGroup.getITNo(s, pt);
var itindex = (pt > 0 && pt == ptCleg || itno < 0 ? 0 : pt > 0 ? JS.SpaceGroup.getITNo(s.substring(pt + 1), 0) : 1);
var data = JS.Symmetry.getAllITAData(vwr, type, false);
if (itindex <= 0) {
return JS.Symmetry.getSpecialSettingJSON(data, name, type, true);
}var list = data[itno - 1].get("its");
return (itindex <= list.size() ? list.get(itindex - 1) : null);
}, "JV.Viewer,~S,~N");
Clazz.overrideMethod(c$, "getConstrainableEquivAtom", 
function(a){
var bsEquiv = this.vwr.ms.getSymmetryEquivAtoms(JU.BSUtil.newAndSetBit(a.i), this, null);
var sgOps = this.getSymmetryOperations();
var ai = a;
for (var i = 9999; i >= 0; i = bsEquiv.nextSetBit(i + 1)) {
if (ai == null) {
ai = this.vwr.ms.at[i];
if (ai === a) continue;
} else if (i == 9999) {
i = -1;
}var inv = this.getInvariantSymops(ai, null);
if (inv.length > 0) {
var op = sgOps[inv[0] - 1];
switch (op.getOpType()) {
case 2:
case 8:
return ai;
}
}ai = null;
}
return a;
}, "JM.Atom");
Clazz.overrideMethod(c$, "setSpinAxisAngle", 
function(aa){
if (this.unitCell != null) this.unitCell.setSpinAxisAngle(aa);
}, "JU.A4");
Clazz.overrideMethod(c$, "getSpinIndex", 
function(op){
var ops = this.getSymmetryOperations();
var id = (ops == null || op >= ops.length ? -1 : ops[op].spinIndex);
return id;
}, "~N");
Clazz.defineMethod(c$, "setSpinSym", 
function(){
if (this.spinSym == null) {
this.spinSym =  new JS.Symmetry();
this.spinSym.spaceGroup = this.spaceGroup;
this.spinSym.unitCell = this.unitCell;
}});
Clazz.overrideMethod(c$, "getSpinSym", 
function(){
return (this.spinSym == null ? this : this.spinSym);
});
Clazz.overrideMethod(c$, "updatePointGroup", 
function(){
return (this.pointGroup == null ? null : this.pointGroup.updateDraw());
});
Clazz.overrideMethod(c$, "toFractionalSpin", 
function(v){
this.unitCell.toFractionalSpin(v);
}, "JU.T3");
Clazz.overrideMethod(c$, "toCartesianSpin", 
function(v){
this.unitCell.toCartesianSpin(v);
}, "JU.T3");
Clazz.overrideMethod(c$, "getQuaternionRotationForUVW", 
function(uvw, planeUVW){
return (this.unitCell == null ? null : this.unitCell.getQuaternionRotationForHKL(uvw, planeUVW));
}, "JU.T3,JU.P4");
c$.nullDesc = null;
c$.aflowStructures = null;
c$.itaData = null;
c$.itaSubData = null;
c$.planeData = null;
c$.layerData = null;
c$.rodData = null;
c$.friezeData = null;
c$.itaSubList = null;
c$.planeSubList = null;
c$.layerSubList = null;
c$.rodSubList = null;
c$.friezeSubList = null;
c$.allDataITA = null;
c$.allPlaneData = null;
c$.allLayerData = null;
c$.allRodData = null;
c$.allFriezeData = null;
c$.planeSubData = null;
c$.layerSubData = null;
c$.rodSubData = null;
c$.friezeSubData = null;
c$.wyckoffFinder = null;
c$.clegInstance = null;
c$.groupFactory = null;
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
