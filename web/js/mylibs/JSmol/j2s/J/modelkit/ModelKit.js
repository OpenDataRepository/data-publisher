Clazz.declarePackage("J.modelkit");
Clazz.load(["JU.Vibration", "JU.BS", "$.P3", "$.P4", "J.i18n.GT"], "J.modelkit.ModelKit", ["java.util.Arrays", "$.Hashtable", "$.TreeMap", "JU.Lst", "$.M4", "$.Measure", "$.P3i", "$.PT", "$.Quat", "$.SB", "$.V3", "J.atomdata.RadiusData", "JU.BSUtil", "$.Edge", "$.Elements", "$.Logger", "$.Point3fi", "$.SimpleUnitCell", "JV.JC"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.vwr = null;
this.menu = null;
this.state = 0;
this.atomHoverLabel = "C";
this.bondHoverLabel = J.i18n.GT.$("increase order");
this.allOperators = null;
this.currentModelIndex = -1;
this.lastModelSet = null;
this.lastElementType = "C";
this.bsHighlight = null;
this.bondIndex = -1;
this.bondAtomIndex1 = -1;
this.bondAtomIndex2 = -1;
this.bsRotateBranch = null;
this.branchAtomIndex = 0;
this.screenXY = null;
this.$isPickAtomAssignCharge = false;
this.isRotateBond = false;
this.showSymopInfo = true;
this.hasUnitCell = false;
this.alertedNoEdit = false;
this.$wasRotating = false;
this.addHydrogens = true;
this.clickToSetElement = true;
this.autoBond = false;
this.centerPoint = null;
this.pickAtomAssignType = "C";
this.pickBondAssignType = 'p';
this.viewOffset = null;
this.centerDistance = 0;
this.symop = null;
this.centerAtomIndex = -1;
this.secondAtomIndex = -1;
this.drawData = null;
this.drawScript = null;
this.iatom0 = 0;
this.lastCenter = "0 0 0";
this.lastOffset = "0 0 0";
this.a0 = null;
this.a3 = null;
this.constraint = null;
this.atomConstraints = null;
this.minBasisAtoms = null;
this.modelSyms = null;
this.minBasis = null;
this.minBasisFixed = null;
this.minBasisModelAtoms = null;
this.minBasisModel = 0;
this.minSelectionSaved = null;
this.minTempFixed = null;
this.minTempModelAtoms = null;
this.keyType = '\0';
this.bsKeyModels = null;
this.bsKeyModelsOFF = null;
this.haveKeys = false;
this.headless = false;
if (!Clazz.isClassDefined("J.modelkit.ModelKit.DrawAtomSet")) {
J.modelkit.ModelKit.$ModelKit$DrawAtomSet$ ();
}
this.drawAtomSymmetry = null;
Clazz.instantialize(this, arguments);}, J.modelkit, "ModelKit", null);
Clazz.prepareFields (c$, function(){
this.bsHighlight =  new JU.BS();
this.screenXY =  Clazz.newIntArray (2, 0);
this.bsKeyModels =  new JU.BS();
this.bsKeyModelsOFF =  new JU.BS();
});
Clazz.makeConstructor(c$, 
function(){
});
c$.getText = Clazz.defineMethod(c$, "getText", 
function(key){
switch (("invSter delAtom dragBon dragAto dragMin dragMol dragMMo incChar decChar rotBond bondTo0 bondTo1 bondTo2 bondTo3 incBond decBond").indexOf(key.substring(0, 7))) {
case 0:
return J.i18n.GT.$("invert ring stereochemistry");
case 8:
return J.i18n.GT.$("delete atom");
case 16:
return J.i18n.GT.$("drag to bond");
case 24:
return J.i18n.GT.$("drag atom");
case 32:
return J.i18n.GT.$("drag atom (and minimize)");
case 40:
return J.i18n.GT.$("drag molecule (ALT to rotate)");
case 48:
return J.i18n.GT.$("drag and minimize molecule (docking)");
case 56:
return J.i18n.GT.$("increase charge");
case 64:
return J.i18n.GT.$("decrease charge");
case 72:
return J.i18n.GT.$("rotate bond");
case 80:
return J.i18n.GT.$("delete bond");
case 88:
return J.i18n.GT.$("single");
case 96:
return J.i18n.GT.$("double");
case 104:
return J.i18n.GT.$("triple");
case 112:
return J.i18n.GT.$("increase order");
case 120:
return J.i18n.GT.$("decrease order");
}
return key;
}, "~S");
c$.getTransform = Clazz.defineMethod(c$, "getTransform", 
function(sym, a, b){
var fa = JU.P3.newP(a);
sym.toFractional(fa, false);
var fb = JU.P3.newP(b);
sym.toFractional(fb, false);
return sym.getTransform(fa, fb, true);
}, "J.api.SymmetryInterface,JM.Atom,JM.Atom");
c$.getKey = Clazz.defineMethod(c$, "getKey", 
function(modelIndex, ext){
return "_!_elkey_" + (modelIndex < 0 ? "" : modelIndex + "_") + (ext == '\0' ? "" : ext + "_");
}, "~N,~S");
c$.isTrue = Clazz.defineMethod(c$, "isTrue", 
function(value){
return (Boolean.$valueOf(value.toString()) === Boolean.TRUE);
}, "~O");
c$.keyToElement = Clazz.defineMethod(c$, "keyToElement", 
function(key){
var ch1 = (key & 0xFF);
var ch2 = (key >> 8) & 0xFF;
var element = "" + String.fromCharCode(ch1) + (ch2 == 0 ? "" : ("" + String.fromCharCode(ch2)).toLowerCase());
var n = JU.Elements.elementNumberFromSymbol(element, true);
return (n == 0 ? null : element);
}, "~N");
c$.notImplemented = Clazz.defineMethod(c$, "notImplemented", 
function(action){
System.err.println("ModelKit.notImplemented(" + action + ")");
}, "~S");
c$.pointFromTriad = Clazz.defineMethod(c$, "pointFromTriad", 
function(pos){
var a = JU.PT.parseFloatArray(JU.PT.replaceAllCharacters(pos, "{,}", " "));
return (a.length == 3 && !Float.isNaN(a[2]) ? JU.P3.new3(a[0], a[1], a[2]) : null);
}, "~S");
Clazz.defineMethod(c$, "actionRotateBond", 
function(deltaX, deltaY, x, y, forceFull){
if (this.bondIndex < 0) return;
var bsBranch = this.bsRotateBranch;
var atomFix;
var atomMove;
var ms = this.vwr.ms;
var b = ms.bo[this.bondIndex];
if (forceFull) {
bsBranch = null;
this.branchAtomIndex = -1;
}if (bsBranch == null) {
atomMove = (this.branchAtomIndex == b.atom1.i ? b.atom1 : b.atom2);
atomFix = (atomMove === b.atom1 ? b.atom2 : b.atom1);
this.vwr.undoMoveActionClear(atomFix.i, 2, true);
if (this.branchAtomIndex >= 0) bsBranch = this.vwr.getBranchBitSet(atomMove.i, atomFix.i, true);
if (bsBranch != null) for (var n = 0, i = atomFix.bonds.length; --i >= 0; ) {
if (bsBranch.get(atomFix.getBondedAtomIndex(i)) && ++n == 2) {
bsBranch = null;
break;
}}
if (bsBranch == null) {
bsBranch = ms.getMoleculeBitSetForAtom(atomFix.i);
forceFull = true;
}this.bsRotateBranch = bsBranch;
this.bondAtomIndex1 = atomFix.i;
this.bondAtomIndex2 = atomMove.i;
} else {
atomFix = ms.at[this.bondAtomIndex1];
atomMove = ms.at[this.bondAtomIndex2];
}if (forceFull) this.bsRotateBranch = null;
var v1 = JU.V3.new3(atomMove.sX - atomFix.sX, atomMove.sY - atomFix.sY, 0);
v1.scale(1 / v1.length());
var v2 = JU.V3.new3(deltaX, deltaY, 0);
v1.cross(v1, v2);
var f = (v1.z > 0 ? 1 : -1);
var degrees = f * (Clazz.doubleToInt(Clazz.floatToInt(v2.length()) / 2) + 1);
if (!forceFull && this.a0 != null) {
var ang0 = JU.Measure.computeTorsion(this.a0, b.atom1, b.atom2, this.a3, true);
var ang1 = Math.round(ang0 + degrees);
degrees = ang1 - ang0;
}var bs = JU.BSUtil.copy(bsBranch);
bs.andNot(this.vwr.slm.getMotionFixedAtoms());
this.vwr.rotateAboutPointsInternal(null, atomFix, atomMove, 0, degrees, false, bs, null, null, null, null, null, true, null);
}, "~N,~N,~N,~N,~B");
Clazz.defineMethod(c$, "addLockedAtoms", 
function(sg, bsLocked){
if (this.vwr.am.cmi < 0 || bsLocked.cardinality() == 0) return;
var bsm = this.vwr.getThisModelAtoms();
var i0 = bsLocked.nextSetBit(0);
if (sg == null && (sg = this.getSym(i0)) == null) return;
for (var i = bsm.nextSetBit(0); i >= 0; i = bsm.nextSetBit(i + 1)) {
if (this.setConstraint(sg, i, J.modelkit.ModelKit.GET_CREATE).type == 6) {
bsLocked.set(i);
}}
}, "J.api.SymmetryInterface,JU.BS");
Clazz.defineMethod(c$, "checkMovedAtoms", 
function(bsFixed, bsAtoms, apos0){
var i0 = bsAtoms.nextSetBit(0);
var n = bsAtoms.cardinality();
var apos =  new Array(n);
try {
var atoms = this.vwr.ms.at;
for (var ip = 0, i = i0; i >= 0; i = bsAtoms.nextSetBit(i + 1)) {
apos[ip++] = JU.P3.newP(atoms[i]);
atoms[i].setT(apos0[i]);
}
var maxSite = 0;
for (var i = i0; i >= 0; i = bsAtoms.nextSetBit(i + 1)) {
var s = this.vwr.ms.at[i].getAtomSite();
if (s > maxSite) maxSite = s;
}
var sites =  Clazz.newIntArray (maxSite, 0);
var p1 =  new JU.P3();
var bsModelAtoms = this.vwr.getModelUndeletedAtomsBitSet(this.vwr.ms.at[i0].mi);
var bsMoved =  new JU.BS();
for (var ip = 0, i = i0; i >= 0; i = bsAtoms.nextSetBit(i + 1), ip++) {
p1.setT(apos[ip]);
var s = this.vwr.ms.at[i].getAtomSite() - 1;
if (sites[s] == 0) {
sites[s] = i + 1;
bsMoved = this.moveConstrained(i, bsFixed, bsModelAtoms, p1, true, false, bsMoved);
if (bsMoved == null) {
n = 0;
break;
}}}
return (n != 0 && this.checkAtomPositions(apos0, apos, bsAtoms) ? n : 0);
} finally {
if (n == 0) {
this.vwr.ms.restoreAtomPositions(apos0);
bsAtoms.clearAll();
} else {
this.updateDrawAtomSymmetry("atomsMoved", bsAtoms);
}}
}, "JU.BS,JU.BS,~A");
Clazz.defineMethod(c$, "checkOption", 
function(type, key){
var check = null;
switch ((type).charCodeAt(0)) {
case 77:
check = ";view;edit;molecular;";
break;
case 83:
check = ";none;applylocal;retainlocal;applyfull;";
break;
case 85:
check = ";packed;extend;";
break;
case 66:
check = ";key;elementkey;labelkey;autobond;hidden;showsymopinfo;clicktosetelement;addhydrogen;addhydrogens;";
break;
}
return (check != null && JU.PT.isOneOf(key.toLowerCase(), check));
}, "~S,~S");
Clazz.defineMethod(c$, "clearAtomConstraints", 
function(){
this.modelSyms = null;
this.minBasisAtoms = null;
if (this.atomConstraints != null) {
for (var i = this.atomConstraints.length; --i >= 0; ) this.atomConstraints[i] = null;

}});
Clazz.defineMethod(c$, "clickAssignAtom", 
function(atomIndex, element, ptNew){
var n = this.addAtomType(element,  Clazz.newArray(-1, [(ptNew == null ? null : ptNew)]), JU.BSUtil.newAndSetBit(atomIndex), null, -1, "click");
if (n > 0) this.vwr.setPickingMode("dragAtom", 0);
}, "~N,~S,JU.P3");
Clazz.defineMethod(c$, "cmdAssignAddAtoms", 
function(type, pts, bsAtoms, packing, cmd){
if (type != null && type.startsWith("_")) type = type.substring(1);
return Math.abs(this.addAtomType(type, pts, bsAtoms, null, packing, cmd));
}, "~S,~A,JU.BS,~N,~S");
Clazz.defineMethod(c$, "cmdAssignAtom", 
function(bs, pt, type, cmd){
if (pt != null && bs != null && bs.cardinality() > 1) bs = JU.BSUtil.newAndSetBit(bs.nextSetBit(0));
if (type.startsWith("_")) type = type.substring(1);
this.assignAtomNoAddedSymmetry(pt, -1, bs, type, (pt != null), cmd, 0);
}, "JU.BS,JU.P3,~S,~S");
Clazz.defineMethod(c$, "cmdAssignBond", 
function(bondIndex, type, cmd){
this.assignBondAndType(bondIndex, this.getBondOrder(type, this.vwr.ms.bo[bondIndex]), type, cmd);
}, "~N,~S,~S");
Clazz.defineMethod(c$, "cmdAssignConnect", 
function(index, index2, type, cmd){
var atoms = this.vwr.ms.at;
var a;
var b;
if (index < 0 || index2 < 0 || index >= atoms.length || index2 >= atoms.length || (a = atoms[index]) == null || (b = atoms[index2]) == null) return;
var state = this.getMKState();
try {
var bond = null;
if (type != '1') {
var bs =  new JU.BS();
bs.set(index);
bs.set(index2);
bs = this.vwr.getBondsForSelectedAtoms(bs);
bond = this.vwr.ms.bo[bs.nextSetBit(0)];
}var bondOrder = this.getBondOrder(type, bond);
var bs1 = this.vwr.ms.getSymmetryEquivAtoms(JU.BSUtil.newAndSetBit(index), null, null);
var bs2 = this.vwr.ms.getSymmetryEquivAtoms(JU.BSUtil.newAndSetBit(index2), null, null);
this.connectAtoms(a.distance(b), bondOrder, bs1, bs2);
if (this.vwr.getOperativeSymmetry() == null) {
bond = a.getBond(b);
if (bond != null) {
bs1.or(bs2);
this.assignBond(bond.index, 1, bs1);
}}} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
e.printStackTrace();
} else {
throw e;
}
} finally {
this.setMKState(state);
}
}, "~N,~N,~S,~S");
Clazz.defineMethod(c$, "cmdAssignSpaceGroup", 
function(transform, sb, cmd, packing, doDraw){
this.clearAtomConstraints();
if (packing >= 0) {
var n;
if (doDraw) {
n = this.cmdAssignAddAtoms("N:G", null, null, packing, cmd);
} else {
var bsModelAtoms = this.vwr.getThisModelAtoms();
n = this.packAssignedSpaceGroup(null, bsModelAtoms, transform, packing, null, cmd);
}sb.append("\n").append(J.i18n.GT.i(J.i18n.GT.$("{0} atoms added"), n));
}if (doDraw) {
var s = this.drawSymmetry("sg", false, -1, null, 2147483647, null, null, null, 0, -2, 0, null, true);
this.appRunScript(s);
}}, "~S,JU.SB,~S,~N,~B");
Clazz.defineMethod(c$, "cmdAssignDeleteAtoms", 
function(bs){
this.clearAtomConstraints();
bs.and(this.vwr.getThisModelAtoms());
bs = this.vwr.ms.getSymmetryEquivAtoms(bs, null, null);
if (!bs.isEmpty()) {
this.vwr.deleteAtoms(bs, false);
}return bs.cardinality();
}, "JU.BS");
Clazz.defineMethod(c$, "cmdPackUnitCell", 
function(sym, bsAtoms, packing){
this.packAssignedSpaceGroup(sym, bsAtoms, null, packing, null, "packUC");
}, "J.api.SymmetryInterface,JU.BS,~N");
Clazz.defineMethod(c$, "cmdFillOABC", 
function(oabc, packing, cmd){
var bsAtoms = this.vwr.getThisModelAtoms();
var n0 = bsAtoms.cardinality();
if (n0 == 0) return 0;
var uc = this.vwr.getSymTemp().getUnitCell(oabc, false, "fill");
var min =  new JU.P3i();
var max =  new JU.P3i();
var sym0 = this.vwr.getOperativeSymmetry();
sym0.adjustRangeMinMax(oabc, packing, null, null, null, null, min, max);
var p =  new JU.P3();
for (var i = min.x; i < max.x; i++) {
for (var j = min.y; j < max.y; j++) {
for (var k = min.z; k < max.z; k++) {
p.set(i, j, k);
sym0.setOffsetPt(p);
this.packAssignedSpaceGroup(sym0, bsAtoms, null, packing, uc, cmd);
}
}
}
p.set(0, 0, 0);
sym0.setOffsetPt(p);
return this.vwr.getThisModelAtoms().cardinality() - n0;
}, "~A,~N,~S");
Clazz.defineMethod(c$, "cmdAssignMoveAtoms", 
function(bsSelected, iatom, p, pts, allowProjection, isMolecule, isCommand){
var sym = this.getSym(iatom);
var n;
if (sym != null) {
if (this.addHydrogens) this.vwr.ms.addConnectedHAtoms(this.vwr.ms.at[iatom], bsSelected);
n = this.assignMoveAtoms(sym, bsSelected, null, null, iatom, p, pts, allowProjection, isMolecule);
if (n == 0 || bsSelected.isEmpty()) {
if (!isCommand) this.vwr.warnAtom(iatom);
} else if (!isCommand) {
this.vwr.warnAtom(-1);
}} else {
n = this.vwr.moveAtomWithHydrogens(iatom, this.addHydrogens ? 1 : 0, 0, 0, p, null);
}return n;
}, "JU.BS,~N,JU.P3,~A,~B,~B,~B");
Clazz.defineMethod(c$, "cmdMinimize", 
function(eval, bsBasis, steps, crit, rangeFixed, flags){
var wasAppend = this.vwr.getBoolean(603979792);
try {
this.vwr.setBooleanProperty("appendNew", true);
this.minimizeXtal(eval, bsBasis, steps, crit, rangeFixed, flags);
} finally {
this.vwr.setBooleanProperty("appendNew", wasAppend);
}
}, "J.api.JmolScriptEvaluator,JU.BS,~N,~N,~N,~N");
Clazz.defineMethod(c$, "cmdRotateAtoms", 
function(bsAtoms, points, endDegrees){
return this.rotateAtoms(bsAtoms, points, endDegrees);
}, "JU.BS,~A,~N");
Clazz.defineMethod(c$, "dispose", 
function(){
if (this.menu != null) {
this.menu.jpiDispose();
this.menu.modelkit = null;
this.menu = null;
}this.vwr = null;
});
Clazz.defineMethod(c$, "getActiveMenu", 
function(){
return (this.menu == null ? "" : this.menu.activeMenu);
});
Clazz.defineMethod(c$, "getDefaultModel", 
function(){
return (this.addHydrogens ? "5\n\nC 0 0 0\nH .63 .63 .63\nH -.63 -.63 .63\nH -.63 .63 -.63\nH .63 -.63 -.63" : "1\n\nC 0 0 0\n");
});
Clazz.defineMethod(c$, "getProperty", 
function(name){
name = name.toLowerCase().intern();
if (name === "exists") return Boolean.TRUE;
if (name === "constraint") {
return this.constraint;
}if (name === "ismolecular") {
return Boolean.$valueOf(this.getMKState() == 0);
}if (name.startsWith("key_")) {
var model = Integer.parseInt(name.substring("key_".length));
return Boolean.$valueOf(J.modelkit.ModelKit.Key.isKeyOn(this, model, '\0') != '\0');
}if (name === "key") {
return Boolean.$valueOf(J.modelkit.ModelKit.Key.isKeyOn(this, this.vwr.am.cmi, '\0') != '\0');
}if (name === "elementkey") {
return Boolean.$valueOf(J.modelkit.ModelKit.Key.isKeyOn(this, this.vwr.am.cmi, 'E') == 'E');
}if (name === "labelkey") {
return Boolean.$valueOf(J.modelkit.ModelKit.Key.isKeyOn(this, this.vwr.am.cmi, 'L') == 'L');
}if (name === "minimizing") return Boolean.$valueOf(this.minBasis != null);
if (name === "alloperators") {
return this.allOperators;
}if (name === "data") {
return this.getinfo();
}return this.setProperty(name, null);
}, "~S");
Clazz.defineMethod(c$, "getRotateBondIndex", 
function(){
return (this.getMKState() == 0 && this.isRotateBond ? this.bondIndex : -1);
});
Clazz.defineMethod(c$, "getSym", 
function(iatom){
var modelIndex = this.vwr.ms.at[iatom].mi;
if (this.modelSyms == null || modelIndex >= this.modelSyms.length) {
this.modelSyms =  new Array(this.vwr.ms.mc);
for (var imodel = this.modelSyms.length; --imodel >= 0; ) {
var sym = this.vwr.ms.getUnitCell(imodel);
if (sym != null && sym.getSymmetryOperations() != null) this.modelSyms[imodel] = sym.setViewer(this.vwr, "modelkit");
}
}return (iatom < 0 ? null : this.modelSyms[modelIndex]);
}, "~N");
Clazz.defineMethod(c$, "handleAssignNew", 
function(pressed, dragged, mp, dragAtomIndex, key){
var inRange = pressed.inRange(10, dragged.x, dragged.y);
if (mp != null && this.handleAtomDragging(mp.countPlusIndices)) return true;
var atomType = (key < 0 ? this.pickAtomAssignType : J.modelkit.ModelKit.keyToElement(key));
if (atomType == null) return false;
var x = (inRange ? pressed.x : dragged.x);
var y = (inRange ? pressed.y : dragged.y);
if (this.vwr.antialiased) {
x <<= 1;
y <<= 1;
}return this.handleAtomOrBondPicked(x, y, mp, dragAtomIndex, atomType, inRange);
}, "JV.MouseState,JV.MouseState,JM.MeasurementPending,~N,~N");
Clazz.defineMethod(c$, "hasConstraint", 
function(iatom, ignoreGeneral, addNew){
var c = this.setConstraint(this.getSym(iatom), iatom, addNew ? J.modelkit.ModelKit.GET_CREATE : J.modelkit.ModelKit.GET);
return (c != null && (!ignoreGeneral || c.type != 7));
}, "~N,~B,~B");
Clazz.defineMethod(c$, "initializeForModel", 
function(isZap){
this.resetBondFields();
this.allOperators = null;
this.currentModelIndex = -999;
this.iatom0 = 0;
this.centerAtomIndex = this.secondAtomIndex = -1;
this.centerPoint = null;
this.symop = null;
this.setDefaultState(0);
if (isZap) {
if (this.keyType != '\0') {
J.modelkit.ModelKit.Key.updateModelKey(this, this.vwr.am.cmi, true);
}this.bsKeyModels.clearAll();
this.bsKeyModelsOFF.clearAll();
}}, "~B");
Clazz.defineMethod(c$, "isHidden", 
function(){
return (this.menu == null || this.menu.hidden);
});
Clazz.defineMethod(c$, "isPickAtomAssignCharge", 
function(){
return this.$isPickAtomAssignCharge;
});
Clazz.defineMethod(c$, "minimizeEnd", 
function(bsBasis2, isEnd){
this.minimizeXtalEnd(bsBasis2, isEnd);
this.vwr.refresh(1, "modelkit minimize");
}, "JU.BS,~B");
Clazz.defineMethod(c$, "moveMinConstrained", 
function(iatom, p, bsAtoms){
var bsMoved = this.moveConstrained(iatom, null, bsAtoms, p, true, true, null);
return (bsMoved == null ? 0 : bsMoved.cardinality());
}, "~N,JU.P3,JU.BS");
Clazz.defineMethod(c$, "setBondMeasure", 
function(bi, mp){
if (this.branchAtomIndex < 0) return null;
var b = this.vwr.ms.bo[bi];
var a1 = b.atom1;
var a2 = b.atom2;
this.a0 = this.a3 = null;
if (a1.getCovalentBondCount() == 1 || a2.getCovalentBondCount() == 1) return null;
mp.addPoint((this.a0 = J.modelkit.ModelKit.getNearestBondedAtom(a1, a2)).i, null, true);
mp.addPoint(a1.i, null, true);
mp.addPoint(a2.i, null, true);
mp.addPoint((this.a3 = J.modelkit.ModelKit.getNearestBondedAtom(a2, a1)).i, null, true);
mp.mad = 50;
mp.inFront = true;
return mp;
}, "~N,JM.MeasurementPending");
Clazz.defineMethod(c$, "setMenu", 
function(menu){
this.menu = menu;
if (menu != null) {
this.vwr = menu.vwr;
menu.modelkit = this;
}this.initializeForModel(false);
}, "J.modelkit.ModelKitPopup");
Clazz.defineMethod(c$, "setProperty", 
function(key, value){
try {
if (key === "headless") {
this.headless = true;
this.vwr = value;
this.setMenu(null);
return this.vwr;
}if (this.vwr == null) return null;
key = key.toLowerCase().intern();
if (key === "hoverlabel") {
return this.getHoverLabel((value).intValue());
}if (key === "initializemodel") {
this.initializeForModel(true);
return null;
}if (key === "atomset") {
this.addAtomSet(value);
return null;
}if (key === "state") {
return this.getAtomSetState();
}if (key === "atomsMoved") {
if (this.drawAtomSymmetry != null) {
this.updateDrawAtomSymmetry(key, (value)[0]);
}return null;
}if (key === "updatemodelkeys") {
var bsModels = (value == null ? null : (value)[1]);
if (this.haveKeys) {
if (value != null && bsModels == null) {
if (this.keyType != 'L') return null;
bsModels = this.vwr.ms.getModelBS((value)[0], false);
}J.modelkit.ModelKit.Key.updateModelKeys(this, bsModels, true);
}if (this.drawAtomSymmetry != null && bsModels != null) {
this.updateDrawAtomSymmetry("atomsDeleted", (value)[0]);
}return null;
}if (key === "updatekeysfromstate") {
J.modelkit.ModelKit.Key.updateKeyFromStateScript(this);
return null;
}if (key === "updateatomkeys") {
J.modelkit.ModelKit.Key.updateKey(this, value);
return null;
}if (key === "setelementkey") {
this.keyType = 'E';
J.modelkit.ModelKit.Key.setKeys(this, J.modelkit.ModelKit.isTrue(value));
return null;
}if (key === "setlabelkey") {
this.keyType = 'L';
J.modelkit.ModelKit.Key.setKeys(this, J.modelkit.ModelKit.isTrue(value));
return null;
}if (key === "frameresized") {
J.modelkit.ModelKit.Key.clearKey(this, -2);
J.modelkit.ModelKit.Key.updateModelKeys(this, null, true);
return null;
}if (key === "key" || key === "elementkey") {
var mi = this.vwr.am.cmi;
var isOn = J.modelkit.ModelKit.isTrue(value);
this.bsKeyModelsOFF.setBitTo(mi, !isOn);
this.bsKeyModels.setBitTo(mi, false);
J.modelkit.ModelKit.Key.setKey(this, mi, isOn);
return isOn ? "true" : "false";
}if (key === "branchatomclicked") {
if (!this.headless && this.isRotateBond && !this.vwr.acm.isHoverable()) this.setBranchAtom((value).intValue(), true);
return null;
}if (key === "branchatomdragged") {
if (this.isRotateBond) this.setBranchAtom((value).intValue(), true);
return null;
}if (key === "hidemenu") {
if (this.menu != null) this.menu.hidePopup();
return null;
}if (key === "constraint") {
this.constraint = null;
this.clearAtomConstraints();
var o = value;
if (o != null) {
var v1 = o[0];
var v2 = o[1];
var plane = o[2];
if (v1 != null && v2 != null) {
this.constraint =  new J.modelkit.ModelKit.Constraint(null, 4,  Clazz.newArray(-1, [v1, v2]));
} else if (plane != null) {
this.constraint =  new J.modelkit.ModelKit.Constraint(null, 5,  Clazz.newArray(-1, [plane]));
} else if (v1 != null) this.constraint =  new J.modelkit.ModelKit.Constraint(null, 6, null);
}return null;
}if (key === "reset") {
return null;
}if (key === "atompickingmode") {
if (this.headless) return null;
if (JU.PT.isOneOf(value, ";identify;off;")) {
this.exitBondRotation(null);
this.vwr.setBooleanProperty("bondPicking", false);
this.vwr.acm.exitMeasurementMode("modelkit");
}if ("dragatom".equals(value)) {
this.setHoverLabel("atomMenu", J.modelkit.ModelKit.getText("dragAtom"));
}return null;
}if (key === "bondpickingmode") {
if (value.equals("deletebond")) {
this.exitBondRotation(J.modelkit.ModelKit.getText("bondTo0"));
} else if (value.equals("identifybond")) {
this.exitBondRotation("");
}return null;
}if (key === "rotatebond") {
var i = (value).intValue();
if (i != this.bondAtomIndex2) this.bondAtomIndex1 = i;
this.bsRotateBranch = null;
return null;
}if (key === "bondindex") {
if (value != null) {
this.setBondIndex((value).intValue(), false);
}return (this.bondIndex < 0 ? null : Integer.$valueOf(this.bondIndex));
}if (key === "rotatebondindex") {
if (value != null) {
this.setBondIndex((value).intValue(), true);
}return (this.bondIndex < 0 ? null : Integer.$valueOf(this.bondIndex));
}if (key === "highlight") {
this.bsHighlight.clearAll();
if (value != null) this.bsHighlight.or(value);
return null;
}if (key === "mode") {
var isEdit = ("edit".equals(value));
this.setMKState("view".equals(value) ? 1 : isEdit ? 2 : 0);
if (isEdit) this.addHydrogens = false;
return null;
}if (key === "symmetry") {
this.setDefaultState(2);
key = (value).toLowerCase().intern();
this.setSymEdit(key === "applylocal" ? 32 : key === "retainlocal" ? 64 : key === "applyfull" ? 128 : 0);
this.showXtalSymmetry();
return null;
}if (key === "unitcell") {
var isPacked = "packed".equals(value);
this.setUnitCell(isPacked ? 0 : 256);
this.viewOffset = (isPacked ? J.modelkit.ModelKit.Pt000 : null);
return null;
}if (key === "center") {
this.setDefaultState(1);
this.centerPoint = value;
this.lastCenter = this.centerPoint.x + " " + this.centerPoint.y + " " + this.centerPoint.z;
this.centerAtomIndex = (Clazz.instanceOf(this.centerPoint,"JM.Atom") ? (this.centerPoint).i : -1);
this.secondAtomIndex = -1;
this.clickProcessAtom(this.centerAtomIndex);
return null;
}if (key === "assignbond") {
this.cmdAssignBond((value).intValue(), this.pickBondAssignType, "click");
return null;
}if (key === "addhydrogen" || key === "addhydrogens") {
if (value != null) this.addHydrogens = J.modelkit.ModelKit.isTrue(value);
return Boolean.$valueOf(this.addHydrogens);
}if (key === "autobond") {
if (value != null) this.autoBond = J.modelkit.ModelKit.isTrue(value);
return Boolean.$valueOf(this.autoBond);
}if (key === "clicktosetelement") {
if (value != null) this.clickToSetElement = J.modelkit.ModelKit.isTrue(value);
return Boolean.$valueOf(this.clickToSetElement);
}if (key === "hidden") {
if (this.menu == null) return Boolean.TRUE;
if (value != null) {
this.menu.hidden = J.modelkit.ModelKit.isTrue(value);
if (this.menu.hidden) this.menu.hidePopup();
this.vwr.setBooleanProperty("modelkitMode", true);
}return Boolean.$valueOf(this.menu.hidden);
}if (key === "showsymopinfo") {
if (value != null) this.showSymopInfo = J.modelkit.ModelKit.isTrue(value);
return Boolean.$valueOf(this.showSymopInfo);
}if (key === "symop") {
this.setDefaultState(1);
if (value != null) {
if (key === "hoverlabel") {
return this.getHoverLabel((value).intValue());
}this.symop = value;
this.showSymop(this.symop);
}return this.symop;
}if (key === "atomtype") {
this.$wasRotating = this.isRotateBond;
this.isRotateBond = false;
if (value != null) {
this.pickAtomAssignType = value;
this.$isPickAtomAssignCharge = (this.pickAtomAssignType.equalsIgnoreCase("pl") || this.pickAtomAssignType.equalsIgnoreCase("mi"));
if (this.$isPickAtomAssignCharge) {
this.setHoverLabel("atomMenu", J.modelkit.ModelKit.getText(this.pickAtomAssignType.equalsIgnoreCase("mi") ? "decCharge" : "incCharge"));
} else if ("X".equals(this.pickAtomAssignType)) {
this.setHoverLabel("atomMenu", J.modelkit.ModelKit.getText("delAtom"));
} else if (this.pickAtomAssignType.equals("Xx")) {
this.setHoverLabel("atomMenu", J.modelkit.ModelKit.getText("dragBond"));
} else {
this.setHoverLabel("atomMenu", "Click or click+drag to bond or for a new " + this.pickAtomAssignType);
this.lastElementType = this.pickAtomAssignType;
}}return this.pickAtomAssignType;
}if (key === "bondtype") {
if (value != null) {
var s = (value).substring(0, 1).toLowerCase();
if (" 0123456pm".indexOf(s) > 0) {
this.pickBondAssignType = s.charAt(0);
this.setHoverLabel("bondMenu", J.modelkit.ModelKit.getText(this.pickBondAssignType == 'm' ? "decBond" : this.pickBondAssignType == 'p' ? "incBond" : "bondTo" + s));
}this.isRotateBond = false;
}return "" + this.pickBondAssignType;
}if (key === "offset") {
if (value === "none") {
this.viewOffset = null;
} else if (value != null) {
this.viewOffset = (Clazz.instanceOf(value,"JU.P3") ? value : J.modelkit.ModelKit.pointFromTriad(value.toString()));
if (this.viewOffset != null) this.setSymViewState(8);
}this.showXtalSymmetry();
return this.viewOffset;
}if (key === "screenxy") {
if (value != null) {
this.screenXY = value;
this.vwr.acm.exitMeasurementMode("modelkit");
}return this.screenXY;
}if (key === "invariant") {
var iatom = (Clazz.instanceOf(value,"JU.BS") ? (value).nextSetBit(0) : -1);
var atom = this.vwr.ms.getAtom(iatom);
return (atom == null ? null : this.vwr.getSymmetryInfo(iatom, null, -1, null, atom, atom, 1275068418, null, 0, 0, 0, null));
}if (key === "distance") {
this.setDefaultState(2);
var d = (value == null ? NaN : Clazz.instanceOf(value, Float) ? (value).floatValue() : JU.PT.parseFloat(value));
if (!Float.isNaN(d)) {
J.modelkit.ModelKit.notImplemented("setProperty: distance");
this.centerDistance = d;
}return Float.$valueOf(this.centerDistance);
}if (key === "addconstraint") {
J.modelkit.ModelKit.notImplemented("setProperty: addConstraint");
return null;
}if (key === "removeconstraint") {
J.modelkit.ModelKit.notImplemented("setProperty: removeConstraint");
return null;
}if (key === "removeallconstraints") {
J.modelkit.ModelKit.notImplemented("setProperty: removeAllConstraints");
return null;
}if (key === "vibration") {
J.modelkit.ModelKit.WyckoffModulation.setVibrationMode(this, value);
return null;
}System.err.println("ModelKit.setProperty? " + key + " " + value);
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
return "?";
} else {
throw e;
}
}
return null;
}, "~S,~O");
Clazz.defineMethod(c$, "showMenu", 
function(x, y){
if (this.menu == null) return;
this.menu.jpiShow(x, y);
}, "~N,~N");
Clazz.defineMethod(c$, "updateMenu", 
function(){
if (this.menu == null) return;
this.menu.jpiUpdateComputedMenus();
});
Clazz.defineMethod(c$, "wasRotating", 
function(){
var b = this.$wasRotating;
this.$wasRotating = false;
return b;
});
Clazz.defineMethod(c$, "checkNewModel", 
function(){
var isNew = false;
if (this.vwr.ms !== this.lastModelSet) {
this.lastModelSet = this.vwr.ms;
isNew = true;
}this.currentModelIndex = Math.max(this.vwr.am.cmi, 0);
this.iatom0 = this.vwr.ms.am[this.currentModelIndex].firstAtomIndex;
return isNew;
});
Clazz.defineMethod(c$, "clickProcessXtal", 
function(id, action){
if (this.processSymop(id, false) || this.headless) return;
action = action.intern();
if (action.startsWith("mkmode_")) {
if (!this.alertedNoEdit && action === "mkmode_edit") {
this.alertedNoEdit = true;
this.vwr.alert("ModelKit xtal edit has not been implemented");
return;
}this.clickProcessMode(action);
} else if (action.startsWith("mksel_")) {
this.clickProcessSel(action);
} else if (action.startsWith("mkselop_")) {
while (action != null) action = this.clickProcessSelOp(action);

} else if (action.startsWith("mksymmetry_")) {
this.clickProcessSym(action);
} else if (action.startsWith("mkunitcell_")) {
this.clickProcessUC(action);
} else {
J.modelkit.ModelKit.notImplemented("XTAL click " + action);
}this.menu.updateAllXtalMenuOptions();
}, "~S,~S");
Clazz.defineMethod(c$, "exitBondRotation", 
function(text){
this.$wasRotating = this.isRotateBond;
this.isRotateBond = false;
if (text != null) this.bondHoverLabel = text;
this.vwr.highlight(null);
}, "~S");
Clazz.defineMethod(c$, "getAllOperators", 
function(){
if (this.allOperators != null) return this.allOperators;
var data = this.runScriptBuffered("show symop");
this.allOperators = JU.PT.split(data.trim().$replace('\t', ' '), "\n");
return this.allOperators;
});
Clazz.defineMethod(c$, "getBasisAtom", 
function(iatom){
if (this.minBasisAtoms == null) {
this.minBasisAtoms =  new Array(this.vwr.ms.ac + 10);
}if (this.minBasisAtoms.length < iatom + 10) {
var a =  new Array(this.vwr.ms.ac + 10);
System.arraycopy(this.minBasisAtoms, 0, a, 0, this.minBasisAtoms.length);
this.minBasisAtoms = a;
}var b = this.minBasisAtoms[iatom];
return (b == null ? (this.minBasisAtoms[iatom] = this.vwr.ms.getBasisAtom(iatom, false)) : b);
}, "~N");
Clazz.defineMethod(c$, "getCenterText", 
function(){
return (this.centerAtomIndex < 0 && this.centerPoint == null ? null : this.centerAtomIndex >= 0 ? this.vwr.getAtomInfo(this.centerAtomIndex) : this.centerPoint.toString());
});
Clazz.defineMethod(c$, "getElementFromUser", 
function(){
var element = this.promptUser(J.i18n.GT.$("Element?"), "");
return (element == null || JU.Elements.elementNumberFromSymbol(element, true) == 0 ? null : element);
});
Clazz.defineMethod(c$, "getMKState", 
function(){
return this.state & 3;
});
Clazz.defineMethod(c$, "getSymEditState", 
function(){
return this.state & 224;
});
Clazz.defineMethod(c$, "getSymopText", 
function(){
return (this.symop == null || this.allOperators == null ? null : Clazz.instanceOf(this.symop, Integer) ? this.allOperators[(this.symop).intValue() - 1] : this.symop.toString());
});
Clazz.defineMethod(c$, "getSymViewState", 
function(){
return this.state & 28;
});
Clazz.defineMethod(c$, "getUnitCellState", 
function(){
return this.state & 1792;
});
Clazz.defineMethod(c$, "isXtalState", 
function(){
return ((this.state & 3) != 0);
});
Clazz.defineMethod(c$, "processMKPropertyItem", 
function(name, TF){
name = name.substring(2);
var pt = name.indexOf("_");
if (pt > 0) {
this.setProperty(name.substring(0, pt), name.substring(pt + 1));
} else {
this.setProperty(name, Boolean.$valueOf(TF));
}}, "~S,~B");
Clazz.defineMethod(c$, "processSymop", 
function(id, isFocus){
var pt = id.indexOf(".mkop_");
if (pt >= 0) {
var op = this.symop;
this.symop = Integer.$valueOf(id.substring(pt + 6));
this.showSymop(this.symop);
if (isFocus) this.symop = op;
return true;
}return false;
}, "~S,~B");
Clazz.defineMethod(c$, "resetAtomPickType", 
function(){
this.setProperty("atomtype", this.lastElementType);
});
Clazz.defineMethod(c$, "setConstraint", 
function(sym, ia, mode){
if (ia < 0) return null;
var a = this.getBasisAtom(ia);
var iatom = a.i;
var ac = (this.atomConstraints != null && iatom < this.atomConstraints.length ? this.atomConstraints[iatom] : null);
if (ac != null || mode != J.modelkit.ModelKit.GET_CREATE) {
if (ac != null && mode == J.modelkit.ModelKit.GET_DELETE) {
this.atomConstraints[iatom] = null;
}return ac;
}if (sym == null) return this.addConstraint(iatom,  new J.modelkit.ModelKit.Constraint(a, 0, null));
a = sym.getConstrainableEquivAtom(a);
var isPlanar = (sym.getDimensionality() == 2);
var ops = sym.getInvariantSymops(a, null);
if (JU.Logger.debugging) System.out.println("MK.getConstraint atomIndex=" + iatom + " symops=" + java.util.Arrays.toString(ops));
if (ops.length == 0 && !isPlanar) return this.addConstraint(iatom,  new J.modelkit.ModelKit.Constraint(a, 7, null));
var plane1 = (isPlanar ? J.modelkit.ModelKit.plane001 : null);
var line1 = null;
for (var i = ops.length; --i >= 0; ) {
var line2 = null;
var c = sym.getSymmetryInfoAtom(this.vwr.ms, iatom, null, ops[i], null, a, null, "invariant", 1275068418, 0, -1, 0, null);
if ((typeof(c)=='string')) {
continue;
} else if (Clazz.instanceOf(c,"JU.P4")) {
var plane = c;
if (plane1 == null) {
plane1 = plane;
continue;
}var line = JU.Measure.getIntersectionPP(plane1, plane);
if (line == null || line.size() == 0) {
return J.modelkit.ModelKit.locked;
}line2 =  Clazz.newArray(-1, [line.get(0), line.get(1)]);
} else if (Clazz.instanceOf(c,"JU.P3")) {
return J.modelkit.ModelKit.locked;
} else {
line2 = c;
}if (line2 != null) {
if (line1 == null) {
line1 = line2;
} else {
var v1 = line1[1];
if (Math.abs(v1.dot(line2[1])) < 0.999) return J.modelkit.ModelKit.locked;
}if (plane1 != null) {
if (Math.abs(plane1.dot(line2[1])) > 0.001) return J.modelkit.ModelKit.locked;
}}}
if (line1 != null) {
line1[0] = JU.P3.newP(a);
}return this.addConstraint(iatom, line1 != null ?  new J.modelkit.ModelKit.Constraint(a, 4, line1) : plane1 != null ?  new J.modelkit.ModelKit.Constraint(a, 5,  Clazz.newArray(-1, [plane1])) :  new J.modelkit.ModelKit.Constraint(a, 7, null));
}, "J.api.SymmetryInterface,~N,~N");
Clazz.defineMethod(c$, "setHasUnitCell", 
function(){
return this.hasUnitCell = (this.vwr.getOperativeSymmetry() != null);
});
Clazz.defineMethod(c$, "setHoverLabel", 
function(mode, text){
if (text == null) return;
if (mode === "bondMenu") {
this.bondHoverLabel = text;
} else if (mode === "atomMenu") {
this.atomHoverLabel = text;
} else if (mode === "xtalMenu") {
this.atomHoverLabel = text;
}}, "~S,~S");
Clazz.defineMethod(c$, "setMKState", 
function(bits){
this.state = (this.state & -4) | (this.hasUnitCell ? bits : 0);
}, "~N");
Clazz.defineMethod(c$, "addAtomType", 
function(type, pts, bsAtoms, opsCtr, packing, cmd){
var sym = this.vwr.getOperativeSymmetry();
var ipt = (type == null ? -1 : type.indexOf(":"));
var wyckoff = (ipt > 0 && ipt == type.length - 2 ? type.substring(ipt + 1) : null);
if (wyckoff != null) {
type = type.substring(0, ipt);
if (sym != null) {
var o = sym.getWyckoffPosition(pts == null ? null : pts[0], wyckoff);
if ("L".equals(wyckoff)) {
var oa = o;
var allPts = oa[0];
for (var i = allPts.length; --i >= 0; ) {
sym.toFractional(allPts[i], true);
sym.unitize(allPts[i]);
sym.toCartesian(allPts[i], true);
}
var elements = oa[1];
pts =  new Array(1);
var n = 0;
var opt = allPts.length - 1;
for (var i = 0, ept = opt * 2; i < allPts.length; i++, ept -= 2) {
pts[0] = allPts[i];
var el = (i == 0 ? type : elements.substring(ept, ept + 2).trim());
n += this.addAtoms(el, pts, bsAtoms, opsCtr, packing, null, cmd);
}
return n;
}if (!(Clazz.instanceOf(o,"JU.P3"))) return 0;
pts =  Clazz.newArray(-1, [o]);
}}return this.addAtoms(type, pts, bsAtoms, opsCtr, packing, null, cmd);
}, "~S,~A,JU.BS,~A,~N,~S");
Clazz.defineMethod(c$, "addAtoms", 
function(type, pts, bsAtoms, opsCtr, packing, uc, cmd){
try {
this.vwr.pushHoldRepaintWhy("modelkit");
var sym = this.vwr.getOperativeSymmetry();
if (type != null) {
var ipt = type.indexOf(":");
var wyckoff = (ipt > 0 && ipt == type.length - 2 ? type.substring(ipt + 1) : null);
if (wyckoff != null) {
type = type.substring(0, ipt);
if (sym != null) {
var o = sym.getWyckoffPosition(null, wyckoff);
if (!(Clazz.instanceOf(o,"JU.P3"))) return 0;
pts =  Clazz.newArray(-1, [o]);
}}}var isPoint = (bsAtoms == null);
var atomIndex = (isPoint ? -1 : bsAtoms.nextSetBit(0));
if (!isPoint && atomIndex < 0 || sym == null && type == null) return 0;
if (pts != null) {
for (var i = 0; i < pts.length; i++) if (pts[i] != null && Float.isNaN(pts[i].x + pts[i].y + pts[i].z)) return 0;

}var n = 0;
if (sym == null) {
if (isPoint) {
if (pts == null) {
return 0;
}for (var i = 0; i < pts.length; i++) this.assignAtomNoAddedSymmetry(pts[i], -1, null, type, true, cmd, -1);

n = -pts.length;
} else {
this.assignAtomNoAddedSymmetry(pts[0], atomIndex, null, type, true, cmd, -1);
n = -1;
}} else {
n = this.addAtomsWithSymmetry(sym, bsAtoms, type, atomIndex, isPoint, pts, opsCtr, packing, uc);
}return n;
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
e.printStackTrace();
return 0;
} else {
throw e;
}
} finally {
this.vwr.popHoldRepaint("modelkit");
}
}, "~S,~A,JU.BS,~A,~N,J.api.SymmetryInterface,~S");
Clazz.defineMethod(c$, "addAtomsWithSymmetry", 
function(sym, bsAtoms, type, atomIndex, isPoint, pts, opsCtr, packing, uc){
var flags = "";
var bsM = this.vwr.getThisModelAtoms();
var n = bsM.cardinality();
if (n == 0) flags = "zapped;" + flags;
var stype = "" + type;
var points =  new JU.Lst();
var site = 0;
var pf = null;
if (pts != null && pts.length == 1 && pts[0] != null) {
pf = JU.P3.newP(pts[0]);
sym.toFractional(pf, false);
if (sym.getDimensionality() == 2) pf.z = 0;
isPoint = true;
}for (var i = bsM.nextSetBit(0); i >= 0; i = bsM.nextSetBit(i + 1)) {
var a = this.vwr.ms.at[i];
var p = JU.Point3fi.newPF(a, i);
sym.toFractional(p, false);
if (pf != null && pf.distanceSquared(p) < 1.96E-6) {
p.mi = (site = a.getAtomSite());
if (type == null || pts == null) type = a.getElementSymbolIso(true);
}points.addLast(p);
}
var nInitial = points.size();
flags = "fromfractional;tocartesian;" + flags;
if (isPoint) {
var bsEquiv = (bsAtoms == null ? null : this.vwr.ms.getSymmetryEquivAtoms(bsAtoms, null, null));
for (var i = 0; i < pts.length; i++) {
this.assignAtoms(JU.Point3fi.newPF(pts[i], 1), atomIndex, bsEquiv, stype, true, null, false, site, sym, points, flags, null, packing, uc);
}
} else {
var sites =  new JU.BS();
var spinSym = sym.getSpinSym();
for (var i = bsAtoms.nextSetBit(0); i >= 0; i = bsAtoms.nextSetBit(i + 1)) {
var a = this.vwr.ms.at[i];
site = a.getAtomSite();
if (sites.get(site)) continue;
sites.set(site);
stype = (type == null ? a.getElementSymbolIso(true) : stype);
this.assignAtoms(this.encodeVib(spinSym, a), -1, null, stype, false, null, false, site, sym, points, flags, opsCtr, packing, uc);
if (opsCtr == null) {
for (var j = points.size(); --j >= nInitial; ) points.removeItemAt(j);

} else {
for (var j = points.size(); --j >= nInitial; ) {
var p = points.get(j);
sym.toFractional(p, false);
}
nInitial = points.size();
}}
}return this.vwr.getThisModelAtoms().cardinality() - n;
}, "J.api.SymmetryInterface,JU.BS,~S,~N,~B,~A,~A,~N,J.api.SymmetryInterface");
Clazz.defineMethod(c$, "encodeVib", 
function(spinSym, a){
var p = JU.Point3fi.newPF(a, a.i);
var vib = this.vwr.ms.getVibration(a.i, false);
if (vib != null) {
var v = JU.V3.newV(vib);
spinSym.toFractionalSpin(v);
p.sX = Math.round(v.x * 100000);
p.sY = Math.round(v.y * 100000);
p.sZ = Math.round(v.z * 100000);
p.sD = Math.round(vib.length() * 100000);
}return p;
}, "J.api.SymmetryInterface,JM.Atom");
Clazz.defineMethod(c$, "setVibrations", 
function(spinSym, bsNew, newPts){
var i0 = -1;
for (var ipt = 0, i = bsNew.nextSetBit(0); i >= 0; i = bsNew.nextSetBit(i + 1)) {
var p = newPts[ipt++];
var va = this.vwr.ms.getVibration(p.i, false);
if (va == null) continue;
if (i0 < 0) i0 = p.i;
var vb = va.clone();
vb.x = p.sX / 100000;
vb.y = p.sY / 100000;
vb.z = p.sZ / 100000;
spinSym.toCartesianSpin(vb);
vb.normalize();
vb.scale(p.sD / 100000);
this.vwr.ms.setAtomVibrationVector(i, vb);
}
if (i0 >= 0) {
var mad = (this.vwr.shm.getShapePropertyIndex(18, "mad", i0)).intValue();
this.vwr.shm.setShapeSizeBs(18, 0,  new J.atomdata.RadiusData(null, mad / 2000, J.atomdata.RadiusData.EnumType.ABSOLUTE, null), bsNew);
}}, "J.api.SymmetryInterface,JU.BS,~A");
Clazz.defineMethod(c$, "addConstraint", 
function(iatom, c){
if (c == null) {
if (this.atomConstraints != null && this.atomConstraints.length > iatom) {
this.atomConstraints[iatom] = null;
}return null;
}if (this.atomConstraints == null) {
this.atomConstraints =  new Array(this.vwr.ms.ac + 10);
}if (this.atomConstraints.length < iatom + 10) {
var a =  new Array(this.vwr.ms.ac + 10);
System.arraycopy(this.atomConstraints, 0, a, 0, this.atomConstraints.length);
this.atomConstraints = a;
}return this.atomConstraints[iatom] = c;
}, "~N,J.modelkit.ModelKit.Constraint");
Clazz.defineMethod(c$, "addInfo", 
function(info, key, value){
if (value != null) info.put(key, value);
}, "java.util.Map,~S,~O");
Clazz.defineMethod(c$, "addOccupiedAtomsToBitset", 
function(bsSelected){
var bs =  new JU.BS();
for (var iatom = bsSelected.nextSetBit(0); iatom >= 0; iatom = bsSelected.nextSetBit(iatom + 1)) {
var a = this.vwr.ms.at[iatom];
if (this.vwr.ms.getOccupancyFloat(a.i) == 100) {
bsSelected.set(a.i);
} else {
bs.clearAll();
this.vwr.ms.getAtomsWithin(0.0001, a, bs, a.mi);
for (var i = bs.nextSetBit(0); i >= 0; i = bs.nextSetBit(i + 1)) {
if (this.vwr.ms.getOccupancyFloat(i) == 100) {
bs.clear(i);
bsSelected.clear(i);
}}
bsSelected.or(bs);
}}
return bsSelected.cardinality();
}, "JU.BS");
Clazz.defineMethod(c$, "appRunScript", 
function(script){
this.vwr.runScript(script);
}, "~S");
Clazz.defineMethod(c$, "assignAtomNoAddedSymmetry", 
function(pt, atomIndex, bs, type, newPoint, cmd, site){
this.assignAtoms((pt == null ? null : JU.Point3fi.newPF(pt, 0)), atomIndex, bs, type, newPoint, cmd, false, site, null, null, null, null, 0, null);
}, "JU.P3,~N,JU.BS,~S,~B,~S,~N");
Clazz.defineMethod(c$, "assignAtoms", 
function(thisPt, atomIndex, bsAtoms, type, newPoint, cmd, isClick, site, sym, points, flags, opsCtr, packing, uc){
if (sym == null) sym = this.vwr.getOperativeSymmetry();
var haveAtomByIndex = (atomIndex >= 0);
var isMultipleAtoms = (bsAtoms != null && bsAtoms.cardinality() > 1);
var nIgnored = 0;
var np = 0;
if (!haveAtomByIndex) atomIndex = (bsAtoms == null ? -1 : bsAtoms.nextSetBit(0));
var atom = (atomIndex < 0 ? null : this.vwr.ms.at[atomIndex]);
var bd = (thisPt != null && atom != null ? thisPt.distance(atom) : -1);
var haveVibs = (thisPt != null && thisPt.sD > 0);
if (points != null) {
np = nIgnored = points.size();
sym.toFractional(thisPt, false);
if (thisPt.z != 0 && sym.getDimensionality() == 2) thisPt.z = 0;
points.addLast(thisPt);
if (newPoint && haveAtomByIndex) {
nIgnored++;
}sym.getEquivPointList(nIgnored, flags + (newPoint && atomIndex < 0 ? "newpt" : ""), opsCtr, packing, points, uc);
}var bsEquiv = (atom == null ? null : sym != null ? this.vwr.ms.getSymmetryEquivAtoms(bsAtoms, sym, null) : bsAtoms == null || bsAtoms.cardinality() == 0 ? JU.BSUtil.newAndSetBit(atomIndex) : bsAtoms);
var bs0 = (bsEquiv == null ? null : sym == null ? JU.BSUtil.newAndSetBit(atomIndex) : JU.BSUtil.copy(bsEquiv));
var mi = (atom == null ? this.vwr.am.cmi : atom.mi);
var ac = this.vwr.ms.ac;
var state = this.getMKState();
var isDelete = type.equals("X");
try {
if (isDelete) {
if (isClick) {
this.setProperty("rotatebondindex", Integer.$valueOf(-1));
}this.setConstraint(null, atomIndex, J.modelkit.ModelKit.GET_DELETE);
}if (thisPt == null && points == null) {
if (atom == null) return;
this.vwr.sm.setStatusStructureModified(atomIndex, mi, 1, cmd, 1, bsEquiv);
for (var i = bsEquiv.nextSetBit(0); i >= 0; i = bsEquiv.nextSetBit(i + 1)) {
this.assignAtom(i, type, this.autoBond, sym == null, isClick);
}
if (!JU.PT.isOneOf(type, ";Mi;Pl;X;")) this.vwr.ms.setAtomNamesAndNumbers(atomIndex, -ac, null, true);
this.vwr.sm.setStatusStructureModified(atomIndex, mi, -1, "OK", 1, bsEquiv);
this.vwr.refresh(3, "assignAtom");
J.modelkit.ModelKit.Key.updateKey(this, null);
return;
}this.setMKState(0);
var newPts;
if (points == null) {
newPts =  Clazz.newArray(-1, [thisPt]);
} else {
newPts =  new Array(Math.max(0, points.size() - np));
for (var i = newPts.length; --i >= 0; ) {
newPts[i] = points.get(np + i);
}
}var vConnections =  new JU.Lst();
var isConnected = false;
if (site == 0) {
if (atom != null) {
if (!isMultipleAtoms) {
vConnections.addLast(atom);
isConnected = true;
} else if (sym != null) {
var p = JU.Point3fi.newPF(atom, atom.i);
sym.toFractional(p, false);
bsAtoms.or(bsEquiv);
var list = sym.getEquivPoints(p, flags, packing);
for (var j = 0, n = list.size(); j < n; j++) {
for (var i = bsAtoms.nextSetBit(0); i >= 0; i = bsAtoms.nextSetBit(i + 1)) {
if (this.vwr.ms.at[i].distanceSquared(list.get(j)) < 0.001) {
vConnections.addLast(this.vwr.ms.at[i]);
bsAtoms.clear(i);
}}
}
}isConnected = (vConnections.size() == newPts.length);
if (isConnected) {
var d = 3.4028235E38;
for (var i = newPts.length; --i >= 0; ) {
var d1 = vConnections.get(i).distance(newPts[i]);
if (d == 3.4028235E38) {
d1 = d;
} else if (Math.abs(d1 - d) > 0.001) {
isConnected = false;
break;
}}
}if (!isConnected) {
vConnections.clear();
}this.vwr.sm.setStatusStructureModified(atomIndex, mi, 3, cmd, 1, null);
}if (thisPt != null || points != null) {
var bsM = this.vwr.getThisModelAtoms();
for (var i = bsM.nextSetBit(0); i >= 0; i = bsM.nextSetBit(i + 1)) {
var as = this.vwr.ms.at[i].getAtomSite();
if (as > site) site = as;
}
site++;
}}var pickingMode = (this.headless ? 0 : this.vwr.acm.getAtomPickingMode());
var isMK = this.vwr.getBoolean(603983903);
var wasHidden = true;
if (this.menu != null) {
wasHidden = this.menu.hidden;
if (!isMK && sym == null) {
this.vwr.setBooleanProperty("modelkitmode", true);
this.menu.hidden = true;
this.menu.allowPopup = false;
}}var htParams =  new java.util.Hashtable();
if (site > 0) htParams.put("fixedSite", Integer.$valueOf(site));
htParams.put("element", type);
var bsNew = this.vwr.addHydrogensInline(bsAtoms, vConnections, newPts, htParams);
if (!bsNew.isEmpty()) {
if (points != null) {
if (newPts.length == bsNew.cardinality()) for (var p = 0, i = bsNew.nextSetBit(0); i >= 0; i = bsNew.nextSetBit(i + 1), p++) {
var pt = newPts[p];
if (pt.mi >= 0) this.vwr.ms.at[i].atomSymmetry = JU.BSUtil.newAndSetBit(pt.mi);
}
}if (haveVibs) {
this.setVibrations(sym.getSpinSym(), bsNew, newPts);
}if (bd > 0 && !isConnected && vConnections.isEmpty()) {
this.connectAtoms(bd, 1, bs0, bsNew);
}}if (!isMK && this.menu != null) {
this.vwr.setBooleanProperty("modelkitmode", false);
this.menu.hidden = wasHidden;
this.menu.allowPopup = true;
this.vwr.acm.setPickingMode(pickingMode);
this.menu.hidePopup();
}var atomIndexNew = bsNew.nextSetBit(0);
if (points == null) {
this.assignAtom(atomIndexNew, type, false, atomIndex >= 0 && sym == null, isClick);
if (atomIndex >= 0) {
var doAutobond = (sym == null && !"H".equals(type));
this.assignAtom(atomIndex, ".", false, doAutobond, isClick);
}this.vwr.ms.setAtomNamesAndNumbers(atomIndexNew, -ac, null, true);
this.vwr.sm.setStatusStructureModified(atomIndexNew, mi, -3, "OK", 1, bsNew);
return;
}if (atomIndexNew >= 0) {
for (var i = atomIndexNew; i >= 0; i = bsNew.nextSetBit(i + 1)) {
this.assignAtom(i, type, false, false, true);
this.vwr.ms.setSite(this.vwr.ms.at[i], -1, false);
this.vwr.ms.setSite(this.vwr.ms.at[i], site, true);
}
this.vwr.ms.updateBasisFromSite(mi);
}var firstAtom = this.vwr.ms.am[mi].firstAtomIndex;
this.vwr.ms.setAtomNamesAndNumbers(firstAtom, -ac, null, true);
this.vwr.sm.setStatusStructureModified(-1, mi, -3, "OK", 1, bsNew);
J.modelkit.ModelKit.Key.updateModelKey(this, mi, true);
} catch (ex) {
if (Clazz.exceptionOf(ex, Exception)){
ex.printStackTrace();
} else {
throw ex;
}
} finally {
this.setMKState(state);
}
}, "JU.Point3fi,~N,JU.BS,~S,~B,~S,~B,~N,J.api.SymmetryInterface,JU.Lst,~S,~A,~N,J.api.SymmetryInterface");
Clazz.defineMethod(c$, "assignAtom", 
function(atomIndex, type, autoBond, addHsAndBond, isClick){
if (isClick) {
if (this.vwr.isModelkitPickingRotateBond()) {
this.bondAtomIndex1 = atomIndex;
return -1;
}if (this.clickProcessAtom(atomIndex) || !this.clickToSetElement && this.vwr.ms.getAtom(atomIndex).getElementNumber() != 1) return -1;
}var atom = this.vwr.ms.at[atomIndex];
if (atom == null) return -1;
this.vwr.ms.clearDB(atomIndex);
if (type == null) type = "C";
var bs =  new JU.BS();
var wasH = (atom.getElementNumber() == 1);
var atomicNumber = ("PPlMiX".indexOf(type) > 0 ? -1 : type.equals("Xx") ? 0 : JU.PT.isUpperCase(type.charAt(0)) ? JU.Elements.elementNumberFromSymbol(type, true) : -1);
var isDelete = false;
if (atomicNumber >= 0) {
var doTaint = (atomicNumber > 1 || !addHsAndBond);
this.vwr.ms.setElement(atom, atomicNumber, doTaint);
this.vwr.shm.setShapeSizeBs(0, 0, this.vwr.rd, JU.BSUtil.newAndSetBit(atomIndex));
this.vwr.ms.setAtomName(atomIndex, type + atom.getAtomNumber(), doTaint);
if (this.vwr.getBoolean(603983903)) this.vwr.ms.am[atom.mi].isModelKit = true;
if (!this.vwr.ms.am[atom.mi].isModelKit || atomicNumber > 1) this.vwr.ms.taintAtom(atomIndex, 0);
} else if (type.toLowerCase().equals("pl")) {
atom.setFormalCharge(atom.getFormalCharge() + 1);
} else if (type.toLowerCase().equals("mi")) {
atom.setFormalCharge(atom.getFormalCharge() - 1);
} else if (type.equals("X")) {
isDelete = true;
} else if (!type.equals(".") || !this.addHydrogens) {
return -1;
}if (!addHsAndBond && !isDelete) return atomicNumber;
if (!wasH) this.vwr.ms.removeUnnecessaryBonds(atom, isDelete);
var dx = 0;
if (atom.getCovalentBondCount() == 1) {
if (atomicNumber == 1) {
dx = 1;
} else {
dx = 1.5;
}}if (dx != 0) {
var v = JU.V3.newVsub(atom, this.vwr.ms.at[atom.getBondedAtomIndex(0)]);
var d = v.length();
v.normalize();
v.scale(dx - d);
this.vwr.ms.setAtomCoordRelative(atomIndex, v.x, v.y, v.z);
}var bsA = JU.BSUtil.newAndSetBit(atomIndex);
if (isDelete) {
this.vwr.deleteAtoms(bsA, false);
}if (atomicNumber != 1 && autoBond) {
this.vwr.ms.validateBspf(false);
bs = this.vwr.ms.getAtomsWithinRadius(1, bsA, false, null, null);
bs.andNot(bsA);
if (bs.nextSetBit(0) >= 0) this.vwr.deleteAtoms(bs, false);
bs = this.vwr.getModelUndeletedAtomsBitSet(atom.mi);
bs.andNot(this.vwr.ms.getAtomBitsMDa(1612709900, null,  new JU.BS()));
this.vwr.ms.makeConnections2(0.1, 1.8, 1, 1073741904, bsA, bs, null, false, false, 0, null);
}if (this.addHydrogens) this.vwr.addHydrogens(bsA, 1);
return atomicNumber;
}, "~N,~S,~B,~B,~B");
Clazz.defineMethod(c$, "assignBond", 
function(bondIndex, bondOrder, bsAtoms){
var bond = this.vwr.ms.bo[bondIndex];
this.vwr.ms.clearDB(bond.atom1.i);
if (bondOrder < 0) return false;
try {
var a1H = (bond.atom1.getElementNumber() == 1);
var isH = (a1H || bond.atom2.getElementNumber() == 1);
if (isH && bondOrder > 1) {
this.vwr.deleteAtoms(JU.BSUtil.newAndSetBit(a1H ? bond.atom1.i : bond.atom2.i), false);
return true;
}if (bondOrder == 0) {
this.vwr.deleteBonds(JU.BSUtil.newAndSetBit(bond.index));
} else {
bond.setOrder(bondOrder | 131072);
if (!isH) {
this.vwr.ms.removeUnnecessaryBonds(bond.atom1, false);
this.vwr.ms.removeUnnecessaryBonds(bond.atom2, false);
}}} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
JU.Logger.error("Exception in seBondOrder: " + e.toString());
} else {
throw e;
}
}
if (bondOrder != 0 && this.addHydrogens) this.vwr.addHydrogens(bsAtoms, 1);
return true;
}, "~N,~N,JU.BS");
Clazz.defineMethod(c$, "assignBondAndType", 
function(bondIndex, bondOrder, type, cmd){
var modelIndex = -1;
var state = this.getMKState();
try {
this.setMKState(0);
var a1 = this.vwr.ms.bo[bondIndex].atom1;
modelIndex = a1.mi;
var ac = this.vwr.ms.ac;
var bsAtoms = JU.BSUtil.newAndSetBit(a1.i);
bsAtoms.set(this.vwr.ms.bo[bondIndex].atom2.i);
this.vwr.sm.setStatusStructureModified(bondIndex, modelIndex, 6, cmd, 1, bsAtoms);
this.assignBond(bondIndex, bondOrder, bsAtoms);
this.vwr.ms.setAtomNamesAndNumbers(a1.i, -ac, null, true);
this.vwr.refresh(3, "setBondOrder");
this.vwr.sm.setStatusStructureModified(bondIndex, modelIndex, -6, "" + type, 1, bsAtoms);
} catch (ex) {
if (Clazz.exceptionOf(ex, Exception)){
JU.Logger.error("assignBond failed");
this.vwr.sm.setStatusStructureModified(bondIndex, modelIndex, -6, "ERROR " + ex, 1, null);
} else {
throw ex;
}
} finally {
this.setMKState(state);
}
}, "~N,~N,~S,~S");
Clazz.defineMethod(c$, "assignMoveAtom", 
function(iatom, pt, bsFixed, bsModelAtoms, bsMoved){
if (Float.isNaN(pt.x) || iatom < 0) return 0;
var bs = JU.BSUtil.newAndSetBit(iatom);
if (bsModelAtoms == null) bsModelAtoms = this.vwr.getThisModelAtoms();
bs.and(bsModelAtoms);
if (bs.isEmpty()) return 0;
var state = this.getMKState();
this.setMKState(0);
var n = 0;
try {
var sym = this.getSym(iatom);
var bseq =  new JU.BS();
this.vwr.ms.getSymmetryEquivAtomsForAtom(iatom, null, bsModelAtoms, bseq);
var c = this.setConstraint(sym, bseq.nextSetBit(0), J.modelkit.ModelKit.GET_CREATE);
if (c.type == 6) {
return 0;
}if (bsFixed != null && !bsFixed.isEmpty()) bseq.andNot(bsFixed);
n = bseq.cardinality();
if (n == 0) {
return 0;
}var a = this.vwr.ms.at[iatom];
var v0 = sym.getInvariantSymops(c.keyAtom, null);
var v1 = sym.getInvariantSymops(pt, v0);
if (v1 != null && (v0 == null || !java.util.Arrays.equals(v0, v1))) {
return 0;
}var points =  new Array(n);
if (!this.fillPointsForMove(sym, bseq, a, pt, points)) {
return 0;
}var ikey = c.keyAtom.i;
bsMoved.or(bseq);
var mi = this.vwr.ms.at[ikey].mi;
this.vwr.sm.setStatusStructureModified(ikey, mi, 3, "dragatom", n, bseq);
for (var k = 0, ia = bseq.nextSetBit(0); ia >= 0; ia = bseq.nextSetBit(ia + 1)) {
var p = points[k++];
this.vwr.ms.setAtomCoord(ia, p.x, p.y, p.z);
}
this.vwr.sm.setStatusStructureModified(ikey, mi, -3, "dragatom", n, bseq);
return n;
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
System.err.println("Modelkit err: " + e);
e.printStackTrace();
return 0;
} else {
throw e;
}
} finally {
this.setMKState(state);
if (n > 0) {
this.updateDrawAtomSymmetry("atomsMoved", bsMoved);
}}
}, "~N,JU.P3,JU.BS,JU.BS,JU.BS");
Clazz.defineMethod(c$, "assignMoveAtoms", 
function(sym, bsSelected, bsFixed, bsModelAtoms, iatom, p, pts, allowProjection, isMolecule){
if (sym == null) sym = this.getSym(iatom);
var npts = bsSelected.cardinality();
if (npts == 0) return 0;
var n = 0;
var i0 = bsSelected.nextSetBit(0);
if (bsFixed == null) bsFixed = this.vwr.getMotionFixedAtoms(sym, null);
if (bsModelAtoms == null) bsModelAtoms = this.vwr.getModelUndeletedAtomsBitSet(this.vwr.ms.at[i0].mi);
if (pts != null) {
if (npts != pts.length) return 0;
var bs =  new JU.BS();
for (var ip = 0, i = bsSelected.nextSetBit(0); i >= 0; i = bsSelected.nextSetBit(i + 1)) {
bs.clearAll();
bs.set(i);
n += this.assignMoveAtoms(sym, bs, bsFixed, bsModelAtoms, i, pts[ip++], null, true, isMolecule);
}
return n;
}var nAtoms = bsSelected.cardinality();
if (bsSelected.intersects(bsFixed)) {
p.x = NaN;
return 0;
}nAtoms = this.addOccupiedAtomsToBitset(bsSelected);
if (nAtoms == 1 && !isMolecule) {
var bsMoved = this.moveConstrained(iatom, bsFixed, bsModelAtoms, p, true, allowProjection, null);
return (bsMoved == null ? 0 : bsMoved.cardinality());
}var p1 = JU.P3.newP(p);
p.x = NaN;
if (this.moveConstrained(iatom, bsFixed, bsModelAtoms, p1, false, true, null) == null) {
return 0;
}var vrel = JU.V3.newV(p1);
vrel.sub(this.vwr.ms.at[iatom]);
var apos0 = this.vwr.ms.saveAtomPositions();
var bsAll = JU.BSUtil.copy(bsSelected);
if (isMolecule) {
var bsTest = JU.BSUtil.copy(bsModelAtoms);
bsTest.andNot(bsSelected);
var bsSites =  new JU.BS();
for (var i = bsSelected.nextSetBit(0); i >= 0; i = bsSelected.nextSetBit(i + 1)) {
bsSites.set(this.vwr.ms.at[i].getAtomSite());
}
for (var i = bsTest.nextSetBit(0); i >= 0; i = bsTest.nextSetBit(i + 1)) {
if (bsSites.get(this.vwr.ms.at[i].getAtomSite())) {
var bs = this.vwr.ms.getMoleculeBitSetForAtom(i);
n = bs.cardinality();
if (n > nAtoms) {
nAtoms = n;
bsTest.andNot(bs);
bsAll = bs;
}}}
if (!bsAll.equals(bsSelected)) this.vwr.select(bsAll, false, 0, true);
}var apos =  new Array(bsAll.cardinality());
var maxSite = 0;
for (var i = bsAll.nextSetBit(0); i >= 0; i = bsAll.nextSetBit(i + 1)) {
var s = this.vwr.ms.at[i].getAtomSite();
if (s > maxSite) maxSite = s;
}
var sites =  Clazz.newIntArray (maxSite, 0);
pts =  new Array(maxSite);
var bsMoved =  new JU.BS();
for (var ip = 0, i = bsAll.nextSetBit(0); i >= 0; i = bsAll.nextSetBit(i + 1), ip++) {
p1.setT(this.vwr.ms.at[i]);
p1.add(vrel);
apos[ip] = JU.P3.newP(p1);
var s = this.vwr.ms.at[i].getAtomSite() - 1;
if (sites[s] == 0) {
if (this.moveConstrained(i, bsFixed, bsModelAtoms, p1, false, true, bsMoved) == null) {
return 0;
}p1.sub(this.vwr.ms.at[i]);
p1.sub(vrel);
pts[s] = JU.P3.newP(p1);
sites[s] = i + 1;
}}
bsMoved.clearAll();
for (var i = sites.length; --i >= 0; ) {
var ia = sites[i] - 1;
if (ia >= 0) {
p1.setT(this.vwr.ms.at[ia]);
p1.add(vrel);
if (this.moveConstrained(ia, bsFixed, bsModelAtoms, p1, true, true, bsMoved) == null) {
bsMoved = null;
break;
}}}
n = (bsMoved == null ? 0 : bsMoved.cardinality());
if (n == 0) {
this.vwr.ms.restoreAtomPositions(apos0);
return 0;
}return (this.checkAtomPositions(apos0, apos, bsAll) ? n : 0);
}, "J.api.SymmetryInterface,JU.BS,JU.BS,JU.BS,~N,JU.P3,~A,~B,~B");
Clazz.defineMethod(c$, "checkAtomPositions", 
function(apos0, apos, bs){
var ok = true;
for (var ip = 0, i = bs.nextSetBit(0); i >= 0; i = bs.nextSetBit(i + 1), ip++) {
if (this.vwr.ms.at[i].distanceSquared(apos[ip]) > 0.00000001) {
ok = false;
break;
}}
if (ok) return true;
this.vwr.ms.restoreAtomPositions(apos0);
return false;
}, "~A,~A,JU.BS");
Clazz.defineMethod(c$, "clickProcessAtom", 
function(atomIndex){
switch (this.getMKState()) {
case 0:
return this.vwr.isModelkitPickingRotateBond();
case 1:
this.centerAtomIndex = atomIndex;
if (this.getSymViewState() == 0) this.setSymViewState(8);
this.showXtalSymmetry();
return true;
case 2:
if (atomIndex == this.centerAtomIndex) return true;
J.modelkit.ModelKit.notImplemented("edit click");
return false;
}
J.modelkit.ModelKit.notImplemented("atom click unknown XTAL state");
return false;
}, "~N");
Clazz.defineMethod(c$, "clickProcessMode", 
function(action){
this.processMKPropertyItem(action, false);
}, "~S");
Clazz.defineMethod(c$, "clickProcessSel", 
function(action){
if (action === "mksel_atom") {
this.centerPoint = null;
this.centerAtomIndex = -1;
this.secondAtomIndex = -1;
} else if (action === "mksel_position") {
var pos = this.promptUser("Enter three fractional coordinates", this.lastCenter);
if (pos == null) return;
this.lastCenter = pos;
var p = J.modelkit.ModelKit.pointFromTriad(pos);
if (p == null) {
this.clickProcessSel(action);
return;
}this.centerAtomIndex = -2147483647;
this.centerPoint = p;
this.showXtalSymmetry();
}}, "~S");
Clazz.defineMethod(c$, "clickProcessSelOp", 
function(action){
this.secondAtomIndex = -1;
if (action === "mkselop_addoffset") {
var pos = this.promptUser("Enter i j k for an offset for viewing the operator - leave blank to clear", this.lastOffset);
if (pos == null) return null;
this.lastOffset = pos;
if (pos.length == 0 || pos === "none") {
this.setProperty("offset", "none");
return null;
}var p = J.modelkit.ModelKit.pointFromTriad(pos);
if (p == null) {
return action;
}this.setProperty("offset", p);
} else if (action === "mkselop_atom2") {
J.modelkit.ModelKit.notImplemented(action);
}return null;
}, "~S");
Clazz.defineMethod(c$, "clickProcessSym", 
function(action){
if (action === "mksymmetry_none") {
this.setSymEdit(0);
} else {
this.processMKPropertyItem(action, false);
}}, "~S");
Clazz.defineMethod(c$, "clickProcessUC", 
function(action){
this.processMKPropertyItem(action, false);
this.showXtalSymmetry();
}, "~S");
Clazz.defineMethod(c$, "connectAtoms", 
function(bd, bondOrder, bs1, bs2){
this.vwr.makeConnections((bd - 0.01), (bd + 0.01), bondOrder, 1073742026, bs1, bs2,  new JU.BS(), false, false, 0);
}, "~N,~N,JU.BS,JU.BS");
Clazz.defineMethod(c$, "fillPointsForMove", 
function(sg, bseq, a, pt, points){
var d = a.distance(pt);
var fa = JU.P3.newP(a);
var fb = JU.P3.newP(pt);
sg.toFractional(fa, false);
sg.toFractional(fb, false);
for (var k = 0, i = bseq.nextSetBit(0); i >= 0; i = bseq.nextSetBit(i + 1)) {
var p = JU.P3.newP(this.vwr.ms.at[i]);
var p0 = JU.P3.newP(p);
sg.toFractional(p, false);
var m = sg.getTransform(fa, p, false);
if (m == null) {
return false;
}var p2 = JU.P3.newP(fb);
m.rotTrans(p2);
sg.toCartesian(p2, false);
if (Math.abs(d - p0.distance(p2)) > 0.001) return false;
points[k++] = p2;
}
fa.setT(points[0]);
sg.toFractional(fa, false);
for (var k = points.length; --k >= 0; ) {
fb.setT(points[k]);
sg.toFractional(fb, false);
var m = sg.getTransform(fa, fb, false);
if (m == null) {
return false;
}for (var i = points.length; --i > k; ) {
if (points[i].distance(points[k]) < 0.1) return false;
}
}
return true;
}, "J.api.SymmetryInterface,JU.BS,JU.P3,JU.P3,~A");
Clazz.defineMethod(c$, "getBondLabel", 
function(atoms){
return atoms[Math.min(this.bondAtomIndex1, this.bondAtomIndex2)].getAtomName() + "-" + atoms[Math.max(this.bondAtomIndex1, this.bondAtomIndex2)].getAtomName();
}, "~A");
Clazz.defineMethod(c$, "getBondOrder", 
function(type, bond){
if (type == '-') type = this.pickBondAssignType;
var bondOrder = type.charCodeAt(0) - 48;
switch ((type).charCodeAt(0)) {
case 48:
case 49:
case 50:
case 51:
case 52:
case 53:
break;
case 112:
case 109:
bondOrder = (JU.Edge.getBondOrderNumberFromOrder(bond.getCovalentOrder()).charAt(0)).charCodeAt(0) - 48 + (type == 'p' ? 1 : -1);
if (bondOrder > 3) bondOrder = 1;
 else if (bondOrder < 0) bondOrder = 3;
break;
default:
return -1;
}
return bondOrder;
}, "~S,JM.Bond");
Clazz.defineMethod(c$, "getHoverLabel", 
function(atomIndex){
if (this.headless) return "";
var state = this.getMKState();
var msg = null;
switch (state) {
case 1:
if (this.symop == null) this.symop = Integer.$valueOf(1);
msg = "view symop " + this.symop + " for " + this.vwr.getAtomInfo(atomIndex);
break;
case 2:
msg = "start editing for " + this.vwr.getAtomInfo(atomIndex);
break;
case 0:
var atoms = this.vwr.ms.at;
if (this.isRotateBond) {
this.setBranchAtom(atomIndex, false);
msg = (this.branchAtomIndex >= 0 ? "rotate branch " + atoms[atomIndex].getAtomName() : "rotate bond for " + this.getBondLabel(atoms));
}if (this.bondIndex < 0) {
if (this.atomHoverLabel.length <= 2) {
msg = this.atomHoverLabel = "Click to change to " + this.atomHoverLabel + " or drag to add " + this.atomHoverLabel;
} else {
msg = atoms[atomIndex].getAtomName() + ": " + this.atomHoverLabel;
this.vwr.highlight(JU.BSUtil.newAndSetBit(atomIndex));
}} else {
if (msg == null) {
switch (this.isRotateBond ? this.bsHighlight.cardinality() : atomIndex >= 0 ? 1 : -1) {
case 0:
this.vwr.highlight(JU.BSUtil.newAndSetBit(atomIndex));
case 1:
case 2:
var a = this.vwr.ms.at[atomIndex];
if (!this.isRotateBond) {
this.menu.setActiveMenu("atomMenu");
if (this.vwr.acm.getAtomPickingMode() == 1) return null;
}if (this.atomHoverLabel.indexOf("charge") >= 0) {
var ch = a.getFormalCharge();
ch += (this.atomHoverLabel.indexOf("increase") >= 0 ? 1 : -1);
msg = this.atomHoverLabel + " to " + (ch > 0 ? "+" : "") + ch;
} else {
msg = this.atomHoverLabel;
}msg = atoms[atomIndex].getAtomName() + ": " + msg;
break;
case -1:
msg = (this.bondHoverLabel.length == 0 ? "" : this.bondHoverLabel + " for ") + this.getBondLabel(atoms);
break;
}
}}break;
}
return msg;
}, "~N");
Clazz.defineMethod(c$, "getinfo", 
function(){
var info =  new java.util.Hashtable();
this.addInfo(info, "addHydrogens", Boolean.$valueOf(this.addHydrogens));
this.addInfo(info, "autobond", Boolean.$valueOf(this.autoBond));
this.addInfo(info, "clickToSetElement", Boolean.$valueOf(this.clickToSetElement));
this.addInfo(info, "hidden", Boolean.$valueOf(this.menu == null || this.menu.hidden));
this.addInfo(info, "showSymopInfo", Boolean.$valueOf(this.showSymopInfo));
this.addInfo(info, "centerPoint", this.centerPoint);
this.addInfo(info, "centerAtomIndex", Integer.$valueOf(this.centerAtomIndex));
this.addInfo(info, "secondAtomIndex", Integer.$valueOf(this.secondAtomIndex));
this.addInfo(info, "symop", this.symop);
this.addInfo(info, "offset", this.viewOffset);
this.addInfo(info, "drawData", this.drawData);
this.addInfo(info, "drawScript", this.drawScript);
this.addInfo(info, "isMolecular", Boolean.$valueOf(this.getMKState() == 0));
return info;
});
c$.getNearestBondedAtom = Clazz.defineMethod(c$, "getNearestBondedAtom", 
function(a1, butNotThis){
var b = a1.bonds;
var a2;
var ret = null;
var zmin = 2147483647;
for (var i = a1.getBondCount(); --i >= 0; ) {
if (b[i] != null && b[i].isCovalent() && (a2 = b[i].getOtherAtom(a1)) !== butNotThis && a2.sZ < zmin) {
zmin = a2.sZ;
ret = a2;
}}
return ret;
}, "JM.Atom,JM.Atom");
Clazz.defineMethod(c$, "handleAtomDragging", 
function(countPlusIndices){
switch (this.getMKState()) {
case 0:
return false;
case 2:
if (countPlusIndices[0] > 2) return true;
J.modelkit.ModelKit.notImplemented("drag atom for XTAL edit");
break;
case 1:
if (this.getSymViewState() == 0) this.setSymViewState(8);
switch (countPlusIndices[0]) {
case 1:
this.centerAtomIndex = countPlusIndices[1];
this.secondAtomIndex = -1;
break;
case 2:
this.centerAtomIndex = countPlusIndices[1];
this.secondAtomIndex = countPlusIndices[2];
break;
}
this.showXtalSymmetry();
return true;
}
return true;
}, "~A");
Clazz.defineMethod(c$, "handleAtomOrBondPicked", 
function(x, y, mp, dragAtomIndex, atomType, inRange){
var isCharge = this.$isPickAtomAssignCharge;
if (mp != null && mp.count == 2) {
this.vwr.undoMoveActionClear(-1, 4146, true);
var a = mp.getAtom(1);
var b = mp.getAtom(2);
var bond = a.getBond(b);
if (bond == null) {
this.cmdAssignConnect(a.i, b.i, '1', "click");
} else {
this.cmdAssignBond(bond.index, 'p', "click");
}} else {
if (atomType.equals("Xx")) {
atomType = this.lastElementType;
}if (inRange) {
this.vwr.undoMoveActionClear(dragAtomIndex, 4, true);
} else {
this.vwr.undoMoveActionClear(-1, 4146, true);
}var a = this.vwr.ms.at[dragAtomIndex];
var wasH = (a != null && a.getElementNumber() == 1);
this.clickAssignAtom(dragAtomIndex, atomType, null);
if (isCharge) {
this.appRunScript("{atomindex=" + dragAtomIndex + "}.label='%C'");
} else {
this.vwr.undoMoveActionClear(-1, 4146, true);
if (a == null || inRange) return false;
if (wasH) {
this.clickAssignAtom(dragAtomIndex, "X", null);
} else {
var ptNew = JU.P3.new3(x, y, a.sZ);
this.vwr.tm.unTransformPoint(ptNew, ptNew);
this.clickAssignAtom(dragAtomIndex, atomType, ptNew);
}}}return true;
}, "~N,~N,JM.MeasurementPending,~N,~S,~B");
Clazz.defineMethod(c$, "minimizeXtal", 
function(eval, bsBasis, steps, crit, rangeFixed, flags){
this.minBasisModel = this.vwr.am.cmi;
this.minSelectionSaved = this.vwr.bsA();
this.vwr.am.setFrame(this.minBasisModel);
this.minBasis = bsBasis;
this.minBasisFixed = this.vwr.getMotionFixedAtoms(null, null);
this.minBasisModelAtoms = this.vwr.getModelUndeletedAtomsBitSet(this.minBasisModel);
var cif = this.vwr.getModelExtract(bsBasis, false, false, "cif");
var tempModelIndex = this.vwr.ms.mc;
var htParams =  new java.util.Hashtable();
htParams.put("eval", eval);
htParams.put("lattice", JU.P3.new3(444, 666, 1));
htParams.put("fileData", cif);
htParams.put("loadScript",  new JU.SB());
if (this.vwr.loadModelFromFile(null, "<temp>", null, null, true, htParams, null, null, 0, " ") != null) return;
var bsBasis2 = JU.BSUtil.copy(this.vwr.ms.am[tempModelIndex].bsAsymmetricUnit);
this.minTempModelAtoms = this.vwr.getModelUndeletedAtomsBitSet(tempModelIndex);
this.vwr.am.setFrame(tempModelIndex);
this.minTempFixed = JU.BSUtil.copy(this.minTempModelAtoms);
this.minTempFixed.andNot(bsBasis2);
this.vwr.getMotionFixedAtoms(null, this.minTempFixed);
this.vwr.minimize(eval, steps, crit, JU.BSUtil.copy(bsBasis2), this.minTempFixed, this.minTempModelAtoms, rangeFixed, flags & -257);
}, "J.api.JmolScriptEvaluator,JU.BS,~N,~N,~N,~N");
Clazz.defineMethod(c$, "minimizeXtalEnd", 
function(bsBasis2, isEnd){
if (this.minBasis == null) return;
if (bsBasis2 != null) {
var pts =  new Array(bsBasis2.cardinality());
for (var p = 0, j = this.minBasis.nextSetBit(0), i = bsBasis2.nextSetBit(0); i >= 0; i = bsBasis2.nextSetBit(i + 1), j = this.minBasis.nextSetBit(j + 1)) {
pts[p++] = JU.P3.newP(this.vwr.ms.at[i].getXYZ());
}
var bs = JU.BSUtil.copy(this.minBasis);
bs.andNot(this.minBasisFixed);
this.assignMoveAtoms(null, bs, this.minBasisFixed, this.minBasisModelAtoms, this.minBasis.nextSetBit(0), null, pts, true, false);
}if (isEnd) {
this.minSelectionSaved = null;
this.minBasis = null;
this.minBasisFixed = null;
this.minTempFixed = null;
this.minTempModelAtoms = null;
this.minBasisModelAtoms = null;
this.minBasisAtoms = null;
this.modelSyms = null;
this.vwr.deleteModels(this.vwr.ms.mc - 1, null);
this.vwr.setSelectionSet(this.minSelectionSaved);
this.vwr.setCurrentModelIndex(this.minBasisModel);
}}, "JU.BS,~B");
Clazz.defineMethod(c$, "moveConstrained", 
function(iatom, bsFixed, bsModelAtoms, ptNew, doAssign, allowProjection, bsMoved){
var sym = this.getSym(iatom);
if (sym == null) {
return null;
}if (bsMoved == null) bsMoved = JU.BSUtil.newAndSetBit(iatom);
var a = this.vwr.ms.at[iatom];
var c = this.constraint;
var minv = null;
if (c == null) {
c = this.setConstraint(sym, iatom, J.modelkit.ModelKit.GET_CREATE);
if (c.type == 6) {
iatom = -1;
} else {
var b = c.keyAtom;
if (a !== b) {
var m = J.modelkit.ModelKit.getTransform(sym, a, b);
if (m == null) {
System.err.println("ModelKit - null matrix for " + iatom + " " + a + " to " + b);
iatom = -1;
} else {
if (!doAssign) {
minv = JU.M4.newM4(m);
minv.invert();
}iatom = b.i;
var p = JU.P3.newP(ptNew);
sym.toFractional(p, false);
m.rotTrans(p);
sym.toCartesian(p, false);
ptNew.setT(p);
}}if (iatom >= 0) c.constrain(b, ptNew, allowProjection);
}} else {
c.constrain(a, ptNew, allowProjection);
}if (iatom >= 0 && !Float.isNaN(ptNew.x)) {
if (!doAssign) {
if (minv != null) {
var p = JU.P3.newP(ptNew);
sym.toFractional(p, false);
minv.rotTrans(p);
sym.toCartesian(p, false);
ptNew.setP(p);
}return bsMoved;
}if (this.assignMoveAtom(iatom, ptNew, bsFixed, bsModelAtoms, bsMoved) == 0) bsMoved = null;
}ptNew.x = NaN;
return bsMoved;
}, "~N,JU.BS,JU.BS,JU.P3,~B,~B,JU.BS");
Clazz.defineMethod(c$, "promptUser", 
function(msg, def){
return this.vwr.prompt(msg, def, false);
}, "~S,~S");
Clazz.defineMethod(c$, "resetBondFields", 
function(){
this.bsRotateBranch = null;
this.branchAtomIndex = this.bondAtomIndex1 = this.bondAtomIndex2 = -1;
});
Clazz.defineMethod(c$, "rotateAtoms", 
function(bsAtoms, points, endDegrees){
var center = points[0];
var p =  new JU.P3();
var i0 = bsAtoms.nextSetBit(0);
var sg = this.getSym(i0);
var bsAU =  new JU.BS();
var bsToMove =  new JU.BS();
for (var i = i0; i >= 0; i = bsAtoms.nextSetBit(i + 1)) {
var bai = this.getBasisAtom(i).i;
if (bsAU.get(bai)) {
continue;
}if (this.setConstraint(sg, bai, J.modelkit.ModelKit.GET_CREATE).type == 6) {
return 0;
}bsAU.set(bai);
bsToMove.set(i);
}
var nAtoms = bsAtoms.cardinality();
var apos0 = this.vwr.ms.saveAtomPositions();
var m = JU.Quat.newVA(JU.V3.newVsub(points[1], points[0]), endDegrees).getMatrix();
var vt =  new JU.V3();
var apos =  new Array(nAtoms);
for (var ip = 0, i = i0; i >= 0; i = bsAtoms.nextSetBit(i + 1)) {
var a = this.vwr.ms.at[i];
p = apos[ip++] = JU.P3.newP(a);
vt.sub2(p, center);
m.rotate(vt);
p.add2(center, vt);
this.setConstraint(sg, i, J.modelkit.ModelKit.GET_CREATE).constrain(a, p, false);
if (Float.isNaN(p.x)) return 0;
}
nAtoms = 0;
var bsFixed = this.vwr.getMotionFixedAtoms(sg, null);
var bsModelAtoms = this.vwr.getModelUndeletedAtomsBitSet(this.vwr.ms.at[i0].mi);
var bsMoved =  new JU.BS();
for (var ip = 0, i = i0; i >= 0; i = bsToMove.nextSetBit(i + 1), ip++) {
nAtoms += this.assignMoveAtom(i, apos[ip], bsFixed, bsModelAtoms, bsMoved);
}
var bs = JU.BSUtil.copy(bsAtoms);
bs.andNot(bsToMove);
return (this.checkAtomPositions(apos0, apos, bs) ? nAtoms : 0);
}, "JU.BS,~A,~N");
Clazz.defineMethod(c$, "runScriptBuffered", 
function(script){
var sb =  new JU.SB();
try {
(this.vwr.eval).runBufferedSafely(script, sb);
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
e.printStackTrace();
} else {
throw e;
}
}
return sb.toString();
}, "~S");
Clazz.defineMethod(c$, "setBondIndex", 
function(index, isRotate){
if (!isRotate && this.vwr.isModelkitPickingRotateBond()) {
this.setProperty("rotatebondindex", Integer.$valueOf(index));
return;
}var haveBond = (this.bondIndex >= 0);
if (!haveBond && index < 0) return;
if (index < 0) {
this.resetBondFields();
return;
}this.bsRotateBranch = null;
this.branchAtomIndex = -1;
this.bondIndex = index;
this.isRotateBond = isRotate;
this.bondAtomIndex1 = this.vwr.ms.bo[index].getAtomIndex1();
this.bondAtomIndex2 = this.vwr.ms.bo[index].getAtomIndex2();
if (this.menu != null) this.menu.setActiveMenu("bondMenu");
}, "~N,~B");
Clazz.defineMethod(c$, "setBranchAtom", 
function(atomIndex, isClick){
var isBondedAtom = (atomIndex == this.bondAtomIndex1 || atomIndex == this.bondAtomIndex2);
if (isBondedAtom) {
this.branchAtomIndex = atomIndex;
this.bsRotateBranch = null;
} else {
this.bsRotateBranch = null;
this.branchAtomIndex = -1;
}}, "~N,~B");
Clazz.defineMethod(c$, "setDefaultState", 
function(mode){
if (!this.hasUnitCell) mode = 0;
if (!this.hasUnitCell || this.isXtalState() != this.hasUnitCell) {
this.setMKState(mode);
switch (mode) {
case 0:
break;
case 1:
if (this.getSymViewState() == 0) this.setSymViewState(8);
break;
case 2:
break;
}
}}, "~N");
Clazz.defineMethod(c$, "setSymEdit", 
function(bits){
this.state = (this.state & -225) | bits;
}, "~N");
Clazz.defineMethod(c$, "setSymViewState", 
function(bits){
this.state = (this.state & -29) | bits;
}, "~N");
Clazz.defineMethod(c$, "setUnitCell", 
function(bits){
this.state = (this.state & -1793) | bits;
}, "~N");
Clazz.defineMethod(c$, "showSymop", 
function(symop){
this.secondAtomIndex = -1;
this.symop = symop;
this.showXtalSymmetry();
}, "~O");
Clazz.defineMethod(c$, "showXtalSymmetry", 
function(){
var script = null;
switch (this.getSymViewState()) {
case 0:
script = "draw * delete";
break;
case 8:
default:
var offset = null;
if (this.secondAtomIndex >= 0) {
script = "draw ID sym symop " + (this.centerAtomIndex < 0 ? this.centerPoint : " {atomindex=" + this.centerAtomIndex + "}") + " {atomindex=" + this.secondAtomIndex + "}";
} else {
offset = this.viewOffset;
if (this.symop == null) this.symop = Integer.$valueOf(1);
var iatom = (this.centerAtomIndex >= 0 ? this.centerAtomIndex : this.centerPoint != null ? -1 : this.iatom0);
script = "draw ID sym symop " + (this.symop == null ? "1" : (typeof(this.symop)=='string') ? "'" + this.symop + "'" : JU.PT.toJSON(null, this.symop)) + (iatom < 0 ? this.centerPoint : " {atomindex=" + iatom + "}") + (offset == null ? "" : " offset " + offset);
}this.drawData = this.runScriptBuffered(script);
this.drawScript = script;
this.drawData = (this.showSymopInfo ? this.drawData.substring(0, this.drawData.indexOf("\n") + 1) : "");
this.appRunScript(";refresh;set echo top right;echo " + this.drawData.$replace('\t', ' '));
break;
}
});
Clazz.defineMethod(c$, "transformAtomsToUnitCell", 
function(sym, oabc, ucname){
var bsAtoms = this.vwr.getThisModelAtoms();
var n = bsAtoms.cardinality();
var isReset = (sym == null || n == 0);
if (!isReset) {
var a = this.vwr.ms.at;
var fxyz = this.getFractionalCoordinates(sym, bsAtoms);
this.vwr.ms.setModelCagePts(-1, oabc, ucname);
sym = this.vwr.getCurrentUnitCell();
for (var j = bsAtoms.nextSetBit(0), k = 0; j >= 0; j = bsAtoms.nextSetBit(j + 1), k++) {
a[j].setT(fxyz[k]);
this.vwr.toCartesianUC(sym, a[j], false);
}
this.vwr.ms.setTaintedAtoms(bsAtoms, 2);
}return isReset;
}, "J.api.SymmetryInterface,~A,~S");
Clazz.defineMethod(c$, "getFractionalCoordinates", 
function(sym, bsAtoms){
var n = bsAtoms.cardinality();
var a = this.vwr.ms.at;
var fxyz =  new Array(n);
for (var j = bsAtoms.nextSetBit(0), k = 0; j >= 0; j = bsAtoms.nextSetBit(j + 1), k++) {
fxyz[k] = JU.P3.newP(a[j]);
this.vwr.toFractionalUC(sym, fxyz[k], false);
}
return fxyz;
}, "J.api.SymmetryInterface,JU.BS");
Clazz.defineMethod(c$, "packAssignedSpaceGroup", 
function(sym, bsAtoms, transform, packing, uc, cmd){
if (sym == null && (sym = this.vwr.getOperativeSymmetry()) == null) return 0;
var opsCtr = (transform == null ? null : sym.getSpaceGroupInfoObj("opsCtr", transform, false, false));
var n0 = bsAtoms.cardinality();
this.addAtoms(null, null, bsAtoms, opsCtr, packing, uc, cmd);
if (uc == null) this.vwr.ms.setSpaceGroup(this.vwr.am.cmi, sym,  new JU.BS());
return this.vwr.getThisModelAtoms().cardinality() - n0;
}, "J.api.SymmetryInterface,JU.BS,~S,~N,J.api.SymmetryInterface,~S");
Clazz.defineMethod(c$, "updateDrawAtomSymmetry", 
function(mode, atoms){
if (this.drawAtomSymmetry == null) return;
var cmd = "";
for (var i = this.drawAtomSymmetry.size(); --i >= 0; ) {
var a = this.drawAtomSymmetry.get(i);
if (mode === "deleteModelAtoms" ? atoms.get(a.bsAtoms.nextSetBit(0)) : atoms.intersects(a.bsAtoms)) {
switch (mode) {
case "deleteModelAtoms":
case "atomsDeleted":
this.drawAtomSymmetry.removeItemAt(i);
break;
case "atomsMoved":
try {
if (!this.checkDrawID(a.id)) {
this.drawAtomSymmetry.removeItemAt(i);
} else {
cmd += a.cmd + "#quiet" + "\n";
}} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
e.printStackTrace();
} else {
throw e;
}
}
break;
}
if (this.drawAtomSymmetry.size() == 0) this.drawAtomSymmetry = null;
}if (cmd.length > 0) this.vwr.evalStringGUI(cmd);
}
}, "~S,JU.BS");
Clazz.defineMethod(c$, "checkDrawID", 
function(id){
var o =  Clazz.newArray(-1, [id + "*", null]);
var exists = this.vwr.shm.getShapePropertyData(22, "checkID", o);
return (exists && o[1] != null);
}, "~S");
Clazz.defineMethod(c$, "drawSymop", 
function(a1, a2){
var s = "({" + a1 + "}) ({" + a2 + "}) ";
var cmd = "draw ID " + "sym" + " symop " + s;
this.vwr.evalStringGUI(cmd);
}, "~N,~N");
Clazz.defineMethod(c$, "getAtomSetState", 
function(){
if (this.drawAtomSymmetry == null || this.drawAtomSymmetry.isEmpty()) return null;
var s = "";
for (var i = 0; i < this.drawAtomSymmetry.size(); i++) {
s += this.drawAtomSymmetry.get(i).getState();
}
return s;
});
Clazz.defineMethod(c$, "addAtomSet", 
function(data){
var tokens = JU.PT.split(data, "|");
var id = tokens[0];
if (id.equals("null")) {
id = "sym_";
}if (!id.endsWith("_")) id += "_";
this.clearAtomSets(id);
if (tokens.length == 1) return;
var a1 = JU.PT.parseInt(tokens[1]);
if (tokens[2].startsWith("({")) tokens[2] = tokens[2].substring(2);
var a2 = JU.PT.parseInt(tokens[2]);
var cmd = tokens[3];
var bs = JU.BSUtil.newAndSetBit(a1);
bs.set(a2);
if (this.drawAtomSymmetry == null) {
this.drawAtomSymmetry =  new JU.Lst();
}this.drawAtomSymmetry.addLast(Clazz.innerTypeInstance(J.modelkit.ModelKit.DrawAtomSet, this, null, bs, id, cmd, data));
}, "~S");
Clazz.defineMethod(c$, "clearAtomSets", 
function(id){
if (this.drawAtomSymmetry == null) return;
for (var i = this.drawAtomSymmetry.size(); --i >= 0; ) {
var a = this.drawAtomSymmetry.get(i);
if (a.id.equals(id)) {
this.drawAtomSymmetry.removeItemAt(i);
return;
}}
}, "~S");
Clazz.defineMethod(c$, "drawUnitCell", 
function(id, ucLattice, swidth){
var sym = this.vwr.getOperativeSymmetry();
if (sym == null) return;
if (swidth.length == 0) swidth = "diameter 3";
var uc = this.vwr.getSymTemp().getUnitCell(sym.getUnitCellVectors(), false, "draw");
uc.setOffsetPt(ucLattice);
var cellRange =  Clazz.newArray(-1, [ new JU.P3(),  new JU.P3()]);
var s = "";
if (id == null) id = "uc";
var val =  Clazz.newArray(-1, [ Clazz.newArray(-1, ["thisID", id + "*"]),  Clazz.newArray(-1, ["delete", null])]);
this.vwr.shm.setShapeProperties(22, val);
JU.SimpleUnitCell.getCellRange(ucLattice, cellRange);
var ext = "";
for (var p = 1, x = Clazz.floatToInt(cellRange[0].x); x < cellRange[1].x; x++) {
for (var y = Clazz.floatToInt(cellRange[0].y); y < cellRange[1].y; y++) {
for (var z = Clazz.floatToInt(cellRange[0].z); z < cellRange[1].z; z++, p++) {
if (p > 1) ext = "_" + p;
s += "\ndraw ID " + JU.PT.esc(id + ext) + " " + swidth + " unitcell \"a,b,c;" + x + "," + y + "," + z + "\"";
}
}
}
s += this.getDrawAxes(null, id, swidth);
this.appRunScript(s);
}, "~S,JU.T3,~S");
Clazz.defineMethod(c$, "drawAxes", 
function(points, id, swidth){
var s = this.getDrawAxes(points, id, swidth);
if (s.length > 0) this.appRunScript(s);
}, "~A,~S,~S");
Clazz.defineMethod(c$, "getDrawAxes", 
function(points, id, swidth){
var havePoints = (points != null);
if (this.vwr.g.axesMode != 603979808 || this.vwr.shm.getShapePropertyIndex(34, "axesTypeXY", 0) === Boolean.TRUE) return "";
if (id == null) id = "uc";
if ("delete".equals(swidth)) {
return "\ndraw ID " + id + "_axis*" + " delete;";
}if (swidth.length == 0) swidth = "diameter 3";
if (swidth.indexOf(".") > 0) swidth += "2";
 else swidth = "diameter " + (JU.PT.parseInt(swidth.substring(9)) + 2);
var origin = null;
var pts;
var opts = null;
var shiftA;
var shiftB;
var shiftC;
if (havePoints) {
origin = points[0];
shiftA = shiftB = shiftC = false;
pts =  Clazz.newArray(-1, [points[1], points[4], points[3]]);
} else {
var sym = this.vwr.getOperativeSymmetry();
if (sym == null) return "";
origin = this.vwr.shm.getShapePropertyIndex(34, "originPoint", 0);
var axisPoints;
pts = axisPoints = this.vwr.shm.getShapePropertyIndex(34, "axisPoints", 0);
var nDim = sym.getDimensionality();
var periodicity = sym.getPeriodicity();
shiftA = ((periodicity & 0x1) == 0);
shiftB = ((periodicity & 0x2) == 0);
shiftC = ((periodicity & 0x4) == 0);
if (shiftA || shiftB || shiftC) {
pts =  Clazz.newArray(-1, [ new JU.P3(),  new JU.P3(),  new JU.P3()]);
opts =  Clazz.newArray(-1, [ new JU.P3(),  new JU.P3(),  new JU.P3()]);
if (shiftA) {
opts[0].sub2(origin, axisPoints[0]);
opts[0].scale(0.5);
pts[0].sub2(origin, opts[0]);
} else if (nDim == 3) {
pts[0] = axisPoints[0];
}if (shiftB) {
opts[1].sub2(origin, axisPoints[1]);
opts[1].scale(0.5);
pts[1].sub2(origin, opts[1]);
} else if (nDim == 3) {
pts[1] = axisPoints[1];
}if (shiftC) {
opts[2].sub2(origin, axisPoints[2]);
opts[2].scale(0.5);
pts[2].sub2(origin, opts[2]);
} else if (nDim == 3) {
pts[2] = axisPoints[2];
}}}var s = "";
var colors =  Clazz.newArray(-1, ["red", "green", "blue"]);
for (var i = 0, a = 6; i < 3; i++, a++) {
s += "\ndraw ID " + JU.PT.esc(id + "_axis_" + JV.JC.axisLabels[a]) + " " + swidth + " line " + (opts == null || i == 0 && !shiftA || i == 1 && !shiftB || i == 2 && !shiftC ? origin : opts[i]) + " " + pts[i] + " color " + colors[i];
}
s += "\ndraw ID " + JU.PT.esc(id) + ";";
return s;
}, "~A,~S,~S");
Clazz.defineMethod(c$, "drawSymmetry", 
function(thisId, isSymop, iatom, xyz, iSym, trans, center, target, intScale, nth, options, opList, isModelkit){
var s = null;
if (options != 0 && options != 1296041985) {
var o = this.vwr.getSymmetryInfo(iatom, xyz, iSym, trans, center, target, 134217751, null, intScale / 100, nth, options, opList);
if (Clazz.instanceOf(o,"JU.P3")) target = o;
 else s = "";
}if (thisId == null) thisId = (isSymop ? "sym_" : "sg");
if (s == null) {
s = this.vwr.getSymmetryInfo(iatom, xyz, iSym, trans, center, target, 135176, thisId, Clazz.doubleToInt(intScale / 100), nth, options, opList);
}if (s != null) {
s = "draw ID \"" + (isSymop ? "sg" : "sym_") + "*\" delete;" + s;
s = "draw ID \"" + thisId + "*\" delete;" + s;
}if (isModelkit) s += ";draw ID sg_axes axes 0.05;";
return s;
}, "~S,~B,~N,~S,~N,JU.P3,JU.P3,JU.P3,~N,~N,~N,~A,~B");
c$.$ModelKit$DrawAtomSet$ = function(){
/*if4*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
Clazz.prepareCallback(this, arguments);
this.bsAtoms = null;
this.cmd = null;
this.id = null;
this.data = null;
Clazz.instantialize(this, arguments);}, J.modelkit.ModelKit, "DrawAtomSet", null);
Clazz.makeConstructor(c$, 
function(bs, id, cmd, data){
this.bsAtoms = bs;
this.cmd = cmd;
this.id = id;
this.data = data;
}, "JU.BS,~S,~S,~S");
Clazz.defineMethod(c$, "getState", 
function(){
return "modelkit set " + "atomset" + " " + JU.PT.esc(this.data) + "\n";
});
/*eoif4*/})();
};
/*if3*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
this.type = 0;
this.offset = null;
this.plane = null;
this.unitVector = null;
this.keyAtom = null;
Clazz.instantialize(this, arguments);}, J.modelkit.ModelKit, "Constraint", null);
Clazz.makeConstructor(c$, 
function(keyAtom, type, params){
this.keyAtom = keyAtom;
this.type = type;
switch (type) {
case 0:
case 7:
case 6:
break;
case 4:
this.offset = params[0];
this.unitVector = JU.V3.newV(params[1]);
this.unitVector.normalize();
break;
case 5:
this.plane = params[0];
break;
default:
throw  new IllegalArgumentException();
}
}, "JM.Atom,~N,~A");
Clazz.defineMethod(c$, "constrain", 
function(ptOld, ptNew, allowProjection){
var v =  new JU.V3();
var p = JU.P3.newP(ptOld);
var d = 0;
switch (this.type) {
case 0:
return;
case 7:
return;
case 6:
ptNew.x = NaN;
return;
case 4:
if (this.keyAtom == null) {
d = JU.Measure.projectOntoAxis(p, this.offset, this.unitVector, v);
if (d * d >= 1.96E-6) {
ptNew.x = NaN;
break;
}}d = JU.Measure.projectOntoAxis(ptNew, this.offset, this.unitVector, v);
break;
case 5:
if (this.keyAtom == null) {
if (Math.abs(JU.Measure.getPlaneProjection(p, this.plane, v, v)) > 0.01) {
ptNew.x = NaN;
break;
}}d = JU.Measure.getPlaneProjection(ptNew, this.plane, v, v);
ptNew.setT(v);
break;
}
if (!allowProjection && Math.abs(d) > 1e-10) {
ptNew.x = NaN;
}}, "JU.P3,JU.P3,~B");
/*eoif3*/})();
/*if3*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
this.mk = null;
this.nAtoms = 0;
this.modelIndex = 0;
this.bsAtoms = null;
Clazz.instantialize(this, arguments);}, J.modelkit.ModelKit, "Key", null);
Clazz.makeConstructor(c$, 
function(mk, modelIndex){
this.mk = mk;
this.bsAtoms = mk.vwr.getModelUndeletedAtomsBitSet(modelIndex);
this.nAtoms = (this.bsAtoms == null ? 0 : this.bsAtoms.cardinality());
this.modelIndex = modelIndex;
}, "J.modelkit.ModelKit,~N");
Clazz.defineMethod(c$, "setVisibilityFlags", 
function(){
var bs = this.mk.vwr.getVisibleFramesBitSet();
this.mk.vwr.shm.getShape(22).setModelVisibilityFlags(bs);
this.mk.vwr.shm.getShape(31).setModelVisibilityFlags(bs);
});
Clazz.defineMethod(c$, "createDrawEcho", 
function(x, y, key, label, color, font){
label = JU.PT.replaceAllCharacters(label, "\"'\n\r\t", "_");
var text = label;
if (text.startsWith("~_")) text = text.substring(2);
this.mk.vwr.shm.setShapeProperties(22, [ Clazz.newArray(-1, ["init", "elementKey"]),  Clazz.newArray(-1, ["thisID", key + "d_" + label]),  Clazz.newArray(-1, ["diameter", Float.$valueOf(2)]),  Clazz.newArray(-1, ["modelIndex", Integer.$valueOf(this.modelIndex)]),  Clazz.newArray(-1, ["points", Integer.$valueOf(0)]),  Clazz.newArray(-1, ["coord", JU.P3.new3(x, y, -3.4028235E38)]),  Clazz.newArray(-1, ["set", null]),  Clazz.newArray(-1, ["color", Integer.$valueOf(color)]),  Clazz.newArray(-1, ["thisID", null])]);
this.mk.vwr.shm.setShapeProperties(31, [ Clazz.newArray(-1, ["thisID", null]),  Clazz.newArray(-1, ["target", key + "e_" + label]),  Clazz.newArray(-1, ["model", Integer.$valueOf(this.modelIndex)]),  Clazz.newArray(-1, ["xypos", JU.P3.new3(x + 1, y - 1, -3.4028235E38)]),  Clazz.newArray(-1, ["text", text]),  Clazz.newArray(-1, ["font", font]),  Clazz.newArray(-1, ["color", Integer.$valueOf(-74566)]),  Clazz.newArray(-1, ["thisID", null])]);
}, "~N,~N,~S,~S,~N,JU.Font");
c$.isKeyOn = Clazz.defineMethod(c$, "isKeyOn", 
function(mk, modelIndex, type){
var data =  Clazz.newArray(-1, [J.modelkit.ModelKit.getKey(modelIndex, type) + "*", null]);
mk.vwr.shm.getShapePropertyData(31, "checkID", data);
if (data[1] == null) return '\0';
var key = data[0];
return (key.indexOf("_E_") >= 0 ? 'E' : key.indexOf("_L_") >= 0 ? 'L' : '\0');
}, "J.modelkit.ModelKit,~N,~S");
c$.updateKeyFromStateScript = Clazz.defineMethod(c$, "updateKeyFromStateScript", 
function(mk){
for (var i = mk.vwr.ms.mc; --i >= 0; ) {
if (J.modelkit.ModelKit.Key.isKeyOn(mk, i, '\0') != '\0') {
mk.bsKeyModels.set(i);
mk.haveKeys = true;
}}
}, "J.modelkit.ModelKit");
c$.setKey = Clazz.defineMethod(c$, "setKey", 
function(mk, modelIndex, isOn){
if (isOn && (modelIndex >= 0 && mk.bsKeyModels.get(modelIndex))) return;
J.modelkit.ModelKit.Key.clearKey(mk, modelIndex);
if (!isOn || modelIndex < 0) return;
switch ((mk.keyType).charCodeAt(0)) {
case 69:
 new J.modelkit.ModelKit.EKey(mk, modelIndex).draw();
break;
case 76:
 new J.modelkit.ModelKit.LKey(mk, modelIndex).draw();
break;
default:
mk.haveKeys = false;
return;
}
mk.bsKeyModels.set(modelIndex);
mk.haveKeys = true;
}, "J.modelkit.ModelKit,~N,~B");
c$.clearKey = Clazz.defineMethod(c$, "clearKey", 
function(mk, modelIndex){
if (!mk.haveKeys) return;
var key = J.modelkit.ModelKit.getKey(modelIndex, '\0') + "*";
var val =  Clazz.newArray(-1, [ Clazz.newArray(-1, ["thisID", key]),  Clazz.newArray(-1, ["delete", null])]);
mk.vwr.shm.setShapeProperties(22, val);
mk.vwr.shm.setShapeProperties(31, val);
switch (modelIndex) {
case -2:
break;
case -1:
mk.bsKeyModels.clearAll();
break;
default:
mk.bsKeyModels.clear(modelIndex);
break;
}
mk.haveKeys = !mk.bsKeyModels.isEmpty();
}, "J.modelkit.ModelKit,~N");
c$.updateKey = Clazz.defineMethod(c$, "updateKey", 
function(mk, bsAtoms){
if (bsAtoms == null) {
J.modelkit.ModelKit.Key.updateModelKey(mk, mk.vwr.am.cmi, true);
return;
}if (bsAtoms.cardinality() == 1) {
J.modelkit.ModelKit.Key.updateModelKey(mk, mk.vwr.ms.at[bsAtoms.nextSetBit(0)].mi, true);
return;
}for (var i = mk.vwr.ms.mc; --i >= 0; ) {
if (mk.vwr.ms.am[i].bsAtoms.intersects(bsAtoms)) {
J.modelkit.ModelKit.Key.updateModelKey(mk, i, true);
}}
}, "J.modelkit.ModelKit,JU.BS");
c$.updateModelKeys = Clazz.defineMethod(c$, "updateModelKeys", 
function(mk, bsModels, forceNew){
if (bsModels == null) bsModels = JU.BSUtil.newBitSet2(0, mk.vwr.ms.mc);
for (var i = bsModels.nextSetBit(0); i >= 0; i = bsModels.nextSetBit(i + 1)) {
J.modelkit.ModelKit.Key.updateModelKey(mk, i, forceNew);
}
}, "J.modelkit.ModelKit,JU.BS,~B");
c$.updateModelKey = Clazz.defineMethod(c$, "updateModelKey", 
function(mk, modelIndex, forceNew){
if (J.modelkit.ModelKit.Key.doUpdateKey(mk, modelIndex)) {
if (forceNew) J.modelkit.ModelKit.Key.clearKey(mk, modelIndex);
J.modelkit.ModelKit.Key.setKey(mk, modelIndex, true);
}}, "J.modelkit.ModelKit,~N,~B");
c$.doUpdateKey = Clazz.defineMethod(c$, "doUpdateKey", 
function(mk, modelIndex){
return modelIndex >= 0 && !mk.vwr.ms.isJmolDataFrame(modelIndex) && !mk.bsKeyModelsOFF.get(modelIndex) && (mk.keyType != '\0' || mk.bsKeyModels.get(modelIndex) || (mk.keyType = J.modelkit.ModelKit.Key.isKeyOn(mk, modelIndex, '\0')) != '\0');
}, "J.modelkit.ModelKit,~N");
c$.setKeys = Clazz.defineMethod(c$, "setKeys", 
function(mk, isOn){
if (isOn) {
J.modelkit.ModelKit.Key.clearKeysOFF(mk);
}J.modelkit.ModelKit.Key.clearKey(mk, -1);
if (isOn) {
J.modelkit.ModelKit.Key.updateModelKeys(mk, null, false);
}}, "J.modelkit.ModelKit,~B");
c$.clearKeysOFF = Clazz.defineMethod(c$, "clearKeysOFF", 
function(mk){
mk.bsKeyModelsOFF.clearAll();
}, "J.modelkit.ModelKit");
/*eoif3*/})();
/*if3*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
this.bsElements = null;
this.elementStrings = null;
this.colors = null;
this.isotopeCounts = null;
Clazz.instantialize(this, arguments);}, J.modelkit.ModelKit, "EKey", J.modelkit.ModelKit.Key);
Clazz.prepareFields (c$, function(){
this.bsElements =  new JU.BS();
this.elementStrings =  Clazz.newArray(120, 10, null);
this.colors =  Clazz.newIntArray (120, 10, 0);
this.isotopeCounts =  Clazz.newIntArray (120, 0);
});
Clazz.makeConstructor(c$, 
function(mk, modelIndex){
Clazz.superConstructor(this, J.modelkit.ModelKit.EKey, [mk, modelIndex]);
if (this.nAtoms == 0) return;
var a = mk.vwr.ms.at;
for (var i = this.bsAtoms.nextSetBit(0); i >= 0; i = this.bsAtoms.nextSetBit(i + 1)) {
var elem = a[i].getElementSymbol();
var elemno = a[i].getElementNumber();
var color = a[i].atomPropertyInt(mk.vwr, 1765808134);
var j = 0;
var niso = this.isotopeCounts[elemno];
for (; j < niso; j++) {
if (this.elementStrings[elemno][j].equals(elem)) {
if (this.colors[elemno][j] != color) {
this.nAtoms = 0;
return;
}break;
}}
if (j < niso) {
continue;
}this.bsElements.set(elemno);
this.isotopeCounts[elemno]++;
this.elementStrings[elemno][j] = elem;
this.colors[elemno][j] = color;
}
}, "J.modelkit.ModelKit,~N");
Clazz.defineMethod(c$, "draw", 
function(){
if (this.nAtoms == 0) return;
var key = J.modelkit.ModelKit.getKey(this.modelIndex, 'E');
var h = this.mk.vwr.getScreenHeight();
var f = Clazz.doubleToInt(h / 20);
var n = this.bsElements.cardinality();
var dy = 5;
if (n * f > 0.9 * h) {
f = Clazz.doubleToInt(0.9 * h / n);
dy = Clazz.doubleToInt(90 / n);
}var font = this.mk.vwr.getFont3D("SansSerif", "Bold", f);
var x = 98 - Clazz.doubleToInt(75 * f * 3 / h);
for (var y = 90, elemno = this.bsElements.nextSetBit(0); elemno >= 0; elemno = this.bsElements.nextSetBit(elemno + 1)) {
n = this.isotopeCounts[elemno];
if (n == 0) continue;
var elem = this.elementStrings[elemno];
for (var j = 0; j < n; j++) {
var label = elem[j];
var color = this.colors[elemno][j];
this.createDrawEcho(x, y, key, label, color, font);
y -= dy;
}
}
this.setVisibilityFlags();
});
/*eoif3*/})();
/*if3*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
this.labels = null;
Clazz.instantialize(this, arguments);}, J.modelkit.ModelKit, "LKey", J.modelkit.ModelKit.Key);
Clazz.makeConstructor(c$, 
function(mk, modelIndex){
Clazz.superConstructor(this, J.modelkit.ModelKit.LKey, [mk, modelIndex]);
if (this.nAtoms == 0) return;
var a = mk.vwr.ms.at;
this.labels =  new java.util.TreeMap();
var colorList =  new JU.Lst();
for (var i = this.bsAtoms.nextSetBit(0); i >= 0; i = this.bsAtoms.nextSetBit(i + 1)) {
if (a[i] == null) continue;
var label = mk.vwr.shm.getShapePropertyIndex(5, "label", i);
if (label == null) {
this.nAtoms = 0;
break;
}if (label.length == 0) continue;
if (label.charAt(0) < 'a') label = "~_" + label;
var color = a[i].atomPropertyInt(mk.vwr, 1765808134);
var iColor = this.labels.get(label);
if (iColor == null) {
iColor = Integer.$valueOf(color);
if (colorList.contains(iColor)) {
this.nAtoms = 0;
break;
}colorList.addLast(iColor);
} else if (iColor.intValue() != color) {
this.nAtoms = 0;
break;
}this.labels.put(label, iColor);
}
if (this.nAtoms == 0) {
this.labels = null;
}}, "J.modelkit.ModelKit,~N");
Clazz.defineMethod(c$, "draw", 
function(){
if (this.nAtoms == 0) return;
var key = J.modelkit.ModelKit.getKey(this.modelIndex, 'L');
var h = this.mk.vwr.getScreenHeight();
var f = Clazz.doubleToInt(h * 20 / 400);
var n = this.labels.size();
var dy = 5;
if (n * f > 0.9 * h) {
f = Clazz.doubleToInt(0.9 * h / n);
dy = Clazz.doubleToInt(90 / n);
}var ik = this.labels.keySet().iterator();
var wmax = 0;
while (ik.hasNext()) {
var label = ik.next();
var w = label.length;
if (label.startsWith("~_")) w -= 2;
if (w > wmax) wmax = w;
}
var x = 98 - Clazz.doubleToInt(75 * f * wmax / h);
var font = this.mk.vwr.getFont3D("SansSerif", "Bold", f);
var it = this.labels.entrySet().iterator();
for (var y = 90; it.hasNext(); ) {
var e = it.next();
var label = e.getKey();
var color = e.getValue().intValue();
this.createDrawEcho(x, y, key, label, color, font);
y -= dy;
}
this.setVisibilityFlags();
});
/*eoif3*/})();
/*if3*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
this.oldVib = null;
this.atom = null;
this.t = 0;
this.baseAtom = null;
this.pt0 = null;
this.ptf = null;
this.sym = null;
this.c = null;
Clazz.instantialize(this, arguments);}, J.modelkit.ModelKit, "WyckoffModulation", JU.Vibration);
Clazz.prepareFields (c$, function(){
this.pt0 =  new JU.P3();
this.ptf =  new JU.P3();
});
Clazz.makeConstructor(c$, 
function(sym, c, atom, baseAtom){
Clazz.superConstructor (this, J.modelkit.ModelKit.WyckoffModulation, []);
this.setType(-3);
this.sym = sym;
this.c = c;
this.atom = atom;
this.baseAtom = baseAtom;
this.x = 1;
}, "J.api.SymmetryInterface,J.modelkit.ModelKit.Constraint,JM.Atom,JM.Atom");
c$.setVibrationMode = Clazz.defineMethod(c$, "setVibrationMode", 
function(mk, value){
var atoms = mk.vwr.ms.at;
var bsAtoms = mk.vwr.getThisModelAtoms();
if (("off").equals(value)) {
for (var i = bsAtoms.nextSetBit(0); i >= 0; i = bsAtoms.nextSetBit(i + 1)) {
var v = atoms[i].getVibrationVector();
if (v != null && v.modDim != -3) continue;
mk.vwr.ms.setAtomVibrationVector(i, (v).oldVib);
}
} else if (("wyckoff").equals(value)) {
for (var i = bsAtoms.nextSetBit(0); i >= 0; i = bsAtoms.nextSetBit(i + 1)) {
var v = atoms[i].getVibrationVector();
if (v != null && v.modDim != -3) continue;
var sym = mk.getSym(i);
var wv = null;
if (sym != null) {
var c = mk.setConstraint(sym, i, J.modelkit.ModelKit.GET_CREATE);
if (c.type != 6) wv =  new J.modelkit.ModelKit.WyckoffModulation(sym, c, atoms[i], mk.getBasisAtom(i));
}mk.vwr.ms.setAtomVibrationVector(i, wv);
}
}mk.vwr.setVibrationPeriod(NaN);
}, "J.modelkit.ModelKit,~O");
Clazz.overrideMethod(c$, "setCalcPoint", 
function(pt, t456, scale, modulationScale){
var v = this.baseAtom.getVibrationVector();
if (v == null || v.modDim != -3) return pt;
var wv = (v);
if (this.sym == null) return pt;
var m = null;
if (wv.atom !== this.atom) {
m = J.modelkit.ModelKit.getTransform(this.sym, wv.atom, this.atom);
if (m == null) return pt;
}if (wv.t != t456.x && (Clazz.floatToInt(t456.x * 10)) % 2 == 0) {
if (this.c.type != 6) {
wv.setPos(this.sym, this.c, scale);
}wv.t = t456.x;
}if (m == null) pt.setT(wv.ptf);
 else m.rotTrans2(wv.ptf, pt);
this.sym.toCartesian(pt, false);
return pt;
}, "JU.T3,JU.T3,~N,~N");
Clazz.defineMethod(c$, "setPos", 
function(sym, c, scale){
this.x = (Math.random() - 0.5) / 10 * scale;
this.y = (Math.random() - 0.5) / 10 * scale;
this.z = (Math.random() - 0.5) / 10 * scale;
this.pt0.setT(this.atom);
this.ptf.setT(this.pt0);
this.ptf.add(this);
c.constrain(this.pt0, this.ptf, true);
sym.toFractional(this.ptf, false);
}, "J.api.SymmetryInterface,J.modelkit.ModelKit.Constraint,~N");
/*eoif3*/})();
c$.locked =  new J.modelkit.ModelKit.Constraint(null, 6, null);
c$.Pt000 =  new JU.P3();
c$.plane001 = JU.P4.new4(0, 0, 1, 0);
c$.GET = 0;
c$.GET_CREATE = 1;
c$.GET_DELETE = 2;
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
