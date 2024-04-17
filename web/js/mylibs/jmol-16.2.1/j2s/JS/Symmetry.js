Clazz.declarePackage("JS");
Clazz.load(["J.api.SymmetryInterface"], "JS.Symmetry", ["java.util.Hashtable", "JU.BS", "$.JSJSONParser", "$.Lst", "$.M4", "$.P3", "$.PT", "$.Rdr", "J.api.Interface", "J.bspt.Bspt", "JS.PointGroup", "$.SpaceGroup", "$.SymmetryInfo", "$.SymmetryOperation", "$.UnitCell", "JU.Escape", "$.Logger", "$.SimpleUnitCell", "JV.FileManager"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.spaceGroup = null;
this.unitCell = null;
this.$isBio = false;
this.pointGroup = null;
this.cip = null;
this.symmetryInfo = null;
this.desc = null;
Clazz.instantialize(this, arguments);}, JS, "Symmetry", null, J.api.SymmetryInterface);
/*LV!1824 unnec constructor*/Clazz.overrideMethod(c$, "isBio", 
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
if (drawID == null && !asInfo && this.pointGroup.textInfo != null) return this.pointGroup.textInfo;
 else if (drawID == null && this.pointGroup.isDrawType(type, index, scale)) return this.pointGroup.drawInfo;
 else if (asInfo && this.pointGroup.info != null) return this.pointGroup.info;
return this.pointGroup.getInfo(modelIndex, drawID, asInfo, type, index, scale);
}, "~N,~S,~B,~S,~N,~N");
Clazz.overrideMethod(c$, "setSpaceGroup", 
function(doNormalize){
if (this.spaceGroup == null) this.spaceGroup = JS.SpaceGroup.getNull(true, doNormalize, false);
}, "~B");
Clazz.overrideMethod(c$, "addSpaceGroupOperation", 
function(xyz, opId){
return this.spaceGroup.addSymmetry(xyz, opId, false);
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
return JS.SpaceGroup.getInfo(this.spaceGroup, name, params, isFull, addNonstandard);
}, "~S,~A,~B,~B");
Clazz.overrideMethod(c$, "getLatticeDesignation", 
function(){
return this.spaceGroup.getLatticeDesignation();
});
Clazz.overrideMethod(c$, "setFinalOperations", 
function(dim, name, atoms, iAtomFirst, noSymmetryCount, doNormalize, filterSymop){
if (name != null && (name.startsWith("bio") || name.indexOf(" *(") >= 0)) this.spaceGroup.name = name;
if (filterSymop != null) {
var lst =  new JU.Lst();
lst.addLast(this.spaceGroup.operations[0]);
for (var i = 1; i < this.spaceGroup.operationCount; i++) if (filterSymop.contains(" " + (i + 1) + " ")) lst.addLast(this.spaceGroup.operations[i]);

this.spaceGroup = JS.SpaceGroup.createSpaceGroup(-1, name + " *(" + filterSymop.trim() + ")", lst, -1);
}this.spaceGroup.setFinalOperationsForAtoms(dim, atoms, iAtomFirst, noSymmetryCount, doNormalize);
}, "~N,~S,~A,~N,~N,~B,~S");
Clazz.overrideMethod(c$, "getSpaceGroupOperation", 
function(i){
return (this.spaceGroup == null || this.spaceGroup.operations == null || i >= this.spaceGroup.operations.length ? null : this.spaceGroup.finalOperations == null ? this.spaceGroup.operations[i] : this.spaceGroup.finalOperations[i]);
}, "~N");
Clazz.overrideMethod(c$, "getSpaceGroupXyz", 
function(i, doNormalize){
return this.spaceGroup.getXyz(i, doNormalize);
}, "~N,~B");
Clazz.overrideMethod(c$, "newSpaceGroupPoint", 
function(pt, i, o, transX, transY, transZ, retPoint){
if (o == null && this.spaceGroup.finalOperations == null) {
var op = this.spaceGroup.operations[i];
if (!op.isFinalized) op.doFinalize();
o = op;
}JS.SymmetryOperation.rotateAndTranslatePoint((o == null ? this.spaceGroup.finalOperations[i] : o), pt, transX, transY, transZ, retPoint);
}, "JU.P3,~N,JU.M4,~N,~N,~N,JU.P3");
Clazz.overrideMethod(c$, "rotateAxes", 
function(iop, axes, ptTemp, mTemp){
return (iop == 0 ? axes : this.spaceGroup.finalOperations[iop].rotateAxes(axes, this.unitCell, ptTemp, mTemp));
}, "~N,~A,JU.P3,JU.M3");
Clazz.overrideMethod(c$, "getSpinOp", 
function(op){
return this.spaceGroup.operations[op].getMagneticOp();
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
return (this.spaceGroup.finalOperations == null ? this.spaceGroup.operations : this.spaceGroup.finalOperations)[iop].rsvs;
}, "~N");
Clazz.overrideMethod(c$, "getSiteMultiplicity", 
function(pt){
return this.spaceGroup.getSiteMultiplicity(pt, this.unitCell);
}, "JU.P3");
Clazz.overrideMethod(c$, "getSpaceGroupName", 
function(){
return (this.symmetryInfo != null ? this.symmetryInfo.sgName : this.spaceGroup != null ? this.spaceGroup.getName() : this.unitCell != null && this.unitCell.name.length > 0 ? "cell=" + this.unitCell.name : "");
});
Clazz.overrideMethod(c$, "getSpaceGroupTitle", 
function(){
if (this.symmetryInfo != null) return this.symmetryInfo.getSpaceGroupTitle();
var s = this.getSpaceGroupName();
if (s.startsWith("cell=")) return s;
return (this.spaceGroup != null ? this.spaceGroup.asString() : this.unitCell != null && this.unitCell.name.length > 0 ? "cell=" + this.unitCell.name : "");
});
Clazz.overrideMethod(c$, "getSpaceGroupNameType", 
function(type){
return (this.spaceGroup == null ? null : this.spaceGroup.getNameType(type, this));
}, "~S");
Clazz.overrideMethod(c$, "setSpaceGroupName", 
function(name){
if (this.spaceGroup != null) this.spaceGroup.setName(name);
}, "~S");
Clazz.overrideMethod(c$, "getLatticeType", 
function(){
return (this.symmetryInfo != null ? this.symmetryInfo.latticeType : this.spaceGroup == null ? 'P' : this.spaceGroup.latticeType);
});
Clazz.overrideMethod(c$, "getIntTableNumber", 
function(){
return (this.symmetryInfo != null ? this.symmetryInfo.intlTableNo : this.spaceGroup == null ? null : this.spaceGroup.intlTableNumber);
});
Clazz.overrideMethod(c$, "getIntTableNumberFull", 
function(){
return (this.symmetryInfo != null ? this.symmetryInfo.intlTableNoFull : this.spaceGroup == null ? null : this.spaceGroup.intlTableNumberFull != null ? this.spaceGroup.intlTableNumberFull : this.spaceGroup.intlTableNumber);
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
(this.symmetryInfo =  new JS.SymmetryInfo()).setSymmetryInfo(null, this.getUnitCellParams(), this.spaceGroup);
return this.symmetryInfo.infoStr;
});
Clazz.overrideMethod(c$, "getSpaceGroupOperationCount", 
function(){
return (this.symmetryInfo != null && this.symmetryInfo.symmetryOperations != null ? this.symmetryInfo.symmetryOperations.length : this.spaceGroup != null && this.spaceGroup.finalOperations != null ? this.spaceGroup.finalOperations.length : 0);
});
Clazz.overrideMethod(c$, "getSymmetryOperations", 
function(){
if (this.symmetryInfo != null) return this.symmetryInfo.symmetryOperations;
if (this.spaceGroup == null) this.spaceGroup = JS.SpaceGroup.getNull(true, false, true);
this.spaceGroup.setFinalOperations();
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
this.unitCell.setPrecision(slop);
return this;
}, "~A,~B,~N");
Clazz.overrideMethod(c$, "unitCellEquals", 
function(uc2){
return ((uc2)).unitCell.isSameAs(this.unitCell.getF2C());
}, "J.api.SymmetryInterface");
Clazz.overrideMethod(c$, "isSymmetryCell", 
function(sym){
var f2c = (this.symmetryInfo == null ? this.unitCell.getF2C() : this.symmetryInfo.spaceGroupF2C);
var ret = ((sym)).unitCell.isSameAs(f2c);
if (this.symmetryInfo != null) {
if (this.symmetryInfo.setIsActiveCell(ret)) {
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
return this.unitCell.moreInfo;
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
Clazz.overrideMethod(c$, "toFractional", 
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
function(){
return this.unitCell.getFractionalOffset();
});
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
var s =  new JS.Symmetry();
s.unitCell = uc;
return s;
});
Clazz.overrideMethod(c$, "getCanonicalCopy", 
function(scale, withOffset){
return this.unitCell.getCanonicalCopy(scale, withOffset);
}, "~N,~B");
Clazz.overrideMethod(c$, "getUnitCellInfoType", 
function(infoType){
return this.unitCell.getInfo(infoType);
}, "~N");
Clazz.overrideMethod(c$, "getUnitCellInfo", 
function(scaled){
return this.unitCell.dumpInfo(false, scaled);
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
var centroidPacked = (minmax[6] == 1);
nextMol : for (var i = moleculeCount; --i >= 0 && bsAtoms.get(molecules[i].firstAtomIndex); ) {
var bs = molecules[i].atomList;
center.set(0, 0, 0);
var n = 0;
for (var j = bs.nextSetBit(0); j >= 0; j = bs.nextSetBit(j + 1)) {
if (isOneMolecule || centroidPacked) {
center.setT(atoms[j]);
if (this.isNotCentroid(center, 1, minmax, centroidPacked)) {
if (isOneMolecule) bsDelete.set(j);
} else if (!isOneMolecule) {
continue nextMol;
}} else {
center.add(atoms[j]);
n++;
}}
if (centroidPacked || n > 0 && this.isNotCentroid(center, n, minmax, false)) bsDelete.or(bs);
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
function(center, n, minmax, centroidPacked){
center.scale(1 / n);
this.toFractional(center, false);
if (centroidPacked) return (center.x + 0.000005 <= minmax[0] || center.x - 0.000005 > minmax[3] || center.y + 0.000005 <= minmax[1] || center.y - 0.000005 > minmax[4] || center.z + 0.000005 <= minmax[2] || center.z - 0.000005 > minmax[5]);
return (center.x + 0.000005 <= minmax[0] || center.x + 0.00005 > minmax[3] || center.y + 0.000005 <= minmax[1] || center.y + 0.00005 > minmax[4] || center.z + 0.000005 <= minmax[2] || center.z + 0.00005 > minmax[5]);
}, "JU.P3,~N,~A,~B");
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
var info = modelSet.getModelAuxiliaryInfo(modelSet.vwr.am.cmi);
if (info != null) sgName = info.get("spaceGroup");
}var cellInfo = null;
if (cellParams != null) {
cellInfo =  new JS.Symmetry().setUnitCellFromParams(cellParams, false, NaN);
}return this.getDesc(modelSet).getSpaceGroupInfo(this, modelIndex, sgName, 0, null, null, null, 0, -1, isFull, isForModel, 0, cellInfo, null);
}, "JM.ModelSet,~S,~N,~B,~A");
Clazz.overrideMethod(c$, "getV0abc", 
function(def, retMatrix){
return (Clazz.instanceOf(def,Array) ? def : JS.UnitCell.getMatrixAndUnitCell(this.unitCell, def, retMatrix));
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
var pt = this.getFractionalOffset();
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
commands.append("\n UNITCELL " + JU.Escape.e(ms.getUnitCell(modelIndex).getUnitCellVectors()));
commands.append("\n MODELKIT SPACEGROUP " + JU.PT.esc(sg));
commands.append("\n UNITCELL " + JU.Escape.e(ms.getUnitCell(modelIndex).getUnitCellVectors()));
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
if (this.symmetryInfo == null || !this.symmetryInfo.isActive) return null;
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
function(vwr, smiles){
vwr.setCursor(3);
var cip = this.getCIPChirality(vwr);
var data = (J.api.Interface.getInterface("JS.CIPDataSmiles", vwr, "script")).setAtomsForSmiles(vwr, smiles);
cip.getChiralityForAtoms(data);
vwr.setCursor(0);
return data.getSmilesChiralityArray();
}, "JV.Viewer,~S");
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
function(vwr, atoms, xyzList, unitCellParams, origin, asString, isAssign, checkSupercell){
return (J.api.Interface.getInterface("JS.SpaceGroupFinder", vwr, "eval")).findSpaceGroup(vwr, atoms, xyzList, unitCellParams, origin, this, asString, isAssign, checkSupercell);
}, "JV.Viewer,JU.BS,~S,~A,JU.T3,~B,~B,~B");
Clazz.overrideMethod(c$, "setSpaceGroupTo", 
function(sg){
this.symmetryInfo = null;
if (Clazz.instanceOf(sg,"JS.SpaceGroup")) {
this.spaceGroup = sg;
} else {
this.spaceGroup = JS.SpaceGroup.getSpaceGroupFromITAName(sg.toString());
}}, "~O");
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
Clazz.overrideMethod(c$, "getEquivPoints", 
function(pts, pt, flags){
var ops = this.getSymmetryOperations();
return (ops == null || this.unitCell == null ? null : this.unitCell.getEquivPoints(pt, flags, ops, pts == null ?  new JU.Lst() : pts, 0, 0));
}, "JU.Lst,JU.P3,~S");
Clazz.overrideMethod(c$, "getEquivPointList", 
function(pts, nIgnored, flags){
var ops = this.getSymmetryOperations();
var newPt = (flags.indexOf("newpt") >= 0);
var zapped = (flags.indexOf("zapped") >= 0);
var n = pts.size();
var tofractional = (flags.indexOf("tofractional") >= 0);
if (flags.indexOf("fromfractional") < 0) {
for (var i = 0; i < pts.size(); i++) {
this.toFractional(pts.get(i), false);
}
}flags += ",fromfractional,tofractional";
var check0 = (nIgnored > 0 ? 0 : n);
var allPoints = (nIgnored == n);
var n0 = (nIgnored > 0 ? nIgnored : n);
if (allPoints) {
nIgnored--;
n0--;
}if (zapped) n0 = 0;
var p0 = (nIgnored > 0 ? pts.get(nIgnored) : null);
if (ops != null || this.unitCell != null) {
for (var i = nIgnored; i < n; i++) {
this.unitCell.getEquivPoints(pts.get(i), flags, ops, pts, check0, n0);
}
}if (!zapped && (pts.size() == nIgnored || pts.get(nIgnored) !== p0 || allPoints || newPt)) n--;
for (var i = n - nIgnored; --i >= 0; ) pts.removeItemAt(nIgnored);

if (!tofractional) {
for (var i = pts.size(); --i >= nIgnored; ) this.toCartesian(pts.get(i), false);

}}, "JU.Lst,~N,~S");
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
function(vwr, p, letter){
if (this.unitCell == null) return "";
var sg = this.spaceGroup;
if (sg == null && this.symmetryInfo != null) {
sg = JS.SpaceGroup.determineSpaceGroupN(this.symmetryInfo.sgName);
if (sg == null) sg = JS.SpaceGroup.getSpaceGroupFromITAName(this.symmetryInfo.intlTableNoFull);
}if (sg == null || sg.intlTableNumber == null) {
return "?";
}if (p == null) {
p = JU.P3.new3(0.45999998, 0.38333333, 0.2875);
} else {
p = JU.P3.newP(p);
this.unitCell.toFractional(p, false);
this.unitCell.unitize(p);
}if (JS.Symmetry.wyckoffFinder == null) {
JS.Symmetry.wyckoffFinder = J.api.Interface.getInterface("JS.WyckoffFinder", null, "symmetry");
}try {
var w = JS.Symmetry.wyckoffFinder.getWyckoffFinder(vwr, sg.intlTableNumberFull);
var mode = (letter == null ? -1 : letter.equalsIgnoreCase("coord") ? -2 : letter.equalsIgnoreCase("coords") ? -3 : letter.endsWith("*") ? (letter.charAt(0)).charCodeAt(0) : 0);
if (mode != 0) {
return w.getStringInfo(this.unitCell, p, mode);
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
}, "JV.Viewer,JU.P3,~S");
Clazz.overrideMethod(c$, "getTransform", 
function(fracA, fracB, best){
return this.getDesc(null).getTransform(this.unitCell, this.getSymmetryOperations(), fracA, fracB, best);
}, "JU.P3,JU.P3,~B");
Clazz.overrideMethod(c$, "isWithinUnitCell", 
function(pt, x, y, z){
return this.unitCell.isWithinUnitCell(x, y, z, pt);
}, "JU.P3,~N,~N,~N");
Clazz.overrideMethod(c$, "checkPeriodic", 
function(pt){
return this.unitCell.checkPeriodic(pt);
}, "JU.P3");
Clazz.overrideMethod(c$, "convertOperation", 
function(xyz, matrix){
if (matrix == null) {
var a =  Clazz.newFloatArray (16, 0);
JS.SymmetryOperation.getMatrixFromString(null, xyz, a, true, false, true);
a[3] /= 12;
a[7] /= 12;
a[11] /= 12;
return JU.M4.newA16(a);
}return JS.SymmetryOperation.getXYZFromMatrixFrac(matrix, false, false, false, true);
}, "~S,JU.M4");
Clazz.overrideMethod(c$, "getSpaceGroupJSON", 
function(vwr, name, sgname, index){
var isSettings = name.equalsIgnoreCase("settings");
var isThis = (isSettings && index == -2147483648);
var s0 = (!isSettings ? name : isThis ? this.getSpaceGroupName() : "" + index);
try {
var itno;
var tm = null;
var isTM;
var isInt;
if (isSettings) {
isTM = false;
isInt = true;
if (isThis) {
itno = JU.PT.parseInt(this.getIntTableNumber());
if (this.spaceGroup == null) {
var sg = this.symmetryInfo.getDerivedSpaceGroup();
if (sg == null) return  new java.util.Hashtable();
sgname = sg.intlTableNumberFull;
} else {
sgname = this.getIntTableNumberFull();
}} else {
itno = index;
}} else {
var pt = sgname.indexOf("(");
isTM = (sgname.endsWith(")") && pt > 0);
if (isTM) {
tm = sgname.substring(pt + 1, sgname.length - 1);
sgname = sgname.substring(0, pt);
}itno = (sgname.equalsIgnoreCase("ALL") ? 0 : JU.PT.parseInt(sgname));
isInt = (itno != -2147483648);
pt = sgname.indexOf('.');
if (!isTM && isInt && index == 0 && pt > 0) {
index = JU.PT.parseInt(sgname.substring(pt + 1));
sgname = sgname.substring(0, pt);
}}if (isInt && (itno > 230 || itno < 0)) throw  new ArrayIndexOutOfBoundsException(itno);
if (isSettings || name.equalsIgnoreCase("ITA")) {
if (itno == 0) {
if (JS.Symmetry.allDataITA == null) JS.Symmetry.allDataITA = this.getResource(vwr, "sg/json/ita_all.json");
return JS.Symmetry.allDataITA;
}if (JS.Symmetry.itaData == null) JS.Symmetry.itaData =  new Array(230);
var resource = JS.Symmetry.itaData[itno - 1];
if (resource == null) JS.Symmetry.itaData[itno - 1] = resource = this.getResource(vwr, "sg/json/ita_" + itno + ".json");
if (resource != null) {
if (index == 0) return resource;
var its = resource.get("its");
if (its != null) {
if (isSettings && !isThis) {
return its;
}for (var i = (isInt ? index : its.size()); --i >= 0; ) {
var map = its.get(i);
if (i == index - 1 || sgname.equals(map.get("itaFull")) || tm != null && tm.equals(map.get("tm"))) {
return map;
}}
if (tm != null) {
}}}} else if (name.equalsIgnoreCase("AFLOW") && tm == null) {
if (JS.Symmetry.aflowStructures == null) JS.Symmetry.aflowStructures = this.getResource(vwr, "sg/json/aflow_structures.json");
if (itno == 0) return JS.Symmetry.aflowStructures;
System.out.println(sgname + " ? " + index);
var data = JS.Symmetry.aflowStructures.get("" + sgname);
if (index <= data.size()) {
return (index == 0 ? data : data.get(index - 1));
}}throw  new IllegalArgumentException(s0);
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
e.printStackTrace();
return e.getMessage();
} else {
throw e;
}
}
}, "JV.Viewer,~S,~S,~N");
Clazz.defineMethod(c$, "getResource", 
function(vwr, resource){
try {
var r = JV.FileManager.getBufferedReaderForResource(vwr, this, "JS/", resource);
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
return JS.UnitCell.createCompatibleUnitCell(this.spaceGroup, params, null, true);
}, "~A");
Clazz.defineMethod(c$, "setCartesianOffset", 
function(origin){
this.unitCell.setCartesianOffset(origin);
}, "JU.T3");
Clazz.defineMethod(c$, "setSymmetryInfoFromFile", 
function(ms, modelIndex, unitCellParams){
var modelAuxiliaryInfo = ms.getModelAuxiliaryInfo(modelIndex);
this.symmetryInfo =  new JS.SymmetryInfo();
var params = this.symmetryInfo.setSymmetryInfo(modelAuxiliaryInfo, unitCellParams, null);
if (params != null) {
this.setUnitCellFromParams(params, modelAuxiliaryInfo.containsKey("jmolData"), NaN);
this.unitCell.moreInfo = modelAuxiliaryInfo.get("moreUnitCellInfo");
modelAuxiliaryInfo.put("infoUnitCell", this.getUnitCellAsArray(false));
this.setOffsetPt(modelAuxiliaryInfo.get("unitCellOffset"));
var matUnitCellOrientation = modelAuxiliaryInfo.get("matUnitCellOrientation");
if (matUnitCellOrientation != null) this.initializeOrientation(matUnitCellOrientation);
var s = this.symmetryInfo.strSUPERCELL;
if (s != null) {
var oabc = this.unitCell.getUnitCellVectors();
oabc[0] =  new JU.P3();
ms.setModelCagePts(modelIndex, oabc, "conventional");
}if (JU.Logger.debugging) JU.Logger.debug("symmetryInfos[" + modelIndex + "]:\n" + this.unitCell.dumpInfo(true, true));
}}, "JM.ModelSet,~N,~A");
c$.nullDesc = null;
c$.aflowStructures = null;
c$.itaData = null;
c$.allDataITA = null;
c$.wyckoffFinder = null;
});
;//5.0.1-v2 Fri Mar 15 15:25:00 CDT 2024
