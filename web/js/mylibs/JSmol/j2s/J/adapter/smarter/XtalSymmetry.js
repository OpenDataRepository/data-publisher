Clazz.declarePackage("J.adapter.smarter");
Clazz.load(["JS.Symmetry", "JU.P3", "$.SB"], "J.adapter.smarter.XtalSymmetry", ["java.util.Hashtable", "JU.A4", "$.BS", "$.Lst", "$.M3", "$.M4", "$.P3i", "$.PT", "$.V3", "J.adapter.smarter.Atom", "JS.SpaceGroup", "$.SymmetryOperation", "$.UnitCell", "JU.BSUtil", "$.Escape", "$.Logger", "$.SimpleUnitCell"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.acr = null;
this.applySymmetryToBonds = false;
this.asc = null;
this.baseSymmetry = null;
this.bondCount0 = 0;
this.bondsFound = null;
this.centroidPacked = false;
this.checkAll = false;
this.checkNearAtoms = false;
this.crystalReaderLatticeOpsOnly = false;
this.disorderMap = null;
this.disorderMapMax = 0;
this.doCentroidUnitCell = false;
this.doNormalize = true;
this.doPackUnitCell = false;
this.filterSymop = null;
this.firstAtom = 0;
this.latticeCells = null;
this.latticeOp = 0;
this.mident = null;
this.minXYZ = null;
this.maxXYZ = null;
this.minXYZ0 = null;
this.maxXYZ0 = null;
this.mTemp = null;
this.ndims = 3;
this.noSymmetryCount = 0;
this.packingRange = 0;
this.ptOffset = null;
this.ptTemp = null;
this.rmin = null;
this.rmax = null;
this.symmetry = null;
this.symmetryRange = 0;
this.trajectoryUnitCells = null;
this.unitCellParams = null;
this.unitCellTranslations = null;
Clazz.instantialize(this, arguments);}, J.adapter.smarter, "XtalSymmetry", null);
Clazz.prepareFields (c$, function(){
this.bondsFound =  new JU.SB();
this.ptOffset =  new JU.P3();
});
Clazz.makeConstructor(c$, 
function(){
});
Clazz.defineMethod(c$, "addRotatedTensor", 
function(a, t, iSym, reset, symmetry){
if (this.ptTemp == null) {
this.ptTemp =  new JU.P3();
this.mTemp =  new JU.M3();
}return a.addTensor((this.acr.getInterface("JU.Tensor")).setFromEigenVectors(symmetry.rotateAxes(iSym, t.eigenVectors, this.ptTemp, this.mTemp), t.eigenValues, t.isIsotropic ? "iso" : t.type, t.id, t), null, reset);
}, "J.adapter.smarter.Atom,JU.Tensor,~N,~B,J.adapter.smarter.XtalSymmetry.FileSymmetry");
Clazz.defineMethod(c$, "applySymmetryBio", 
function(thisBiomolecule, applySymmetryToBonds, filter){
var biomts = thisBiomolecule.get("biomts");
var len = biomts.size();
if (this.mident == null) {
this.mident =  new JU.M4();
this.mident.setIdentity();
}this.acr.lstNCS = null;
this.setLatticeCells();
var lc = (this.latticeCells != null && this.latticeCells[0] != 0 ?  Clazz.newIntArray (3, 0) : null);
if (lc != null) for (var i = 0; i < 3; i++) lc[i] = this.latticeCells[i];

this.latticeCells = null;
var sep = "";
var bmChains = this.acr.getFilterWithCase("BMCHAINS");
var fixBMChains = -1;
if (bmChains != null && (bmChains = bmChains.trim()).length > 0) {
if (bmChains.charAt(0) == '=') bmChains = bmChains.substring(1).trim();
if (bmChains.equals("_")) {
sep = "_";
fixBMChains = 0;
} else {
fixBMChains = (bmChains.length == 0 ? 0 : JU.PT.parseInt(bmChains));
if (fixBMChains == -2147483648) {
fixBMChains = -(bmChains.charAt(0)).charCodeAt(0);
}}}var particleMode = (filter.indexOf("BYCHAIN") >= 0 ? 1 : filter.indexOf("BYSYMOP") >= 0 ? 2 : 0);
this.doNormalize = false;
var biomtchains = thisBiomolecule.get("chains");
(this.symmetry =  new J.adapter.smarter.XtalSymmetry.FileSymmetry()).setSpaceGroup(this.doNormalize);
this.addSpaceGroupOperation("x,y,z", false);
var name = thisBiomolecule.get("name");
this.setAtomSetSpaceGroupName(this.acr.sgName = name);
this.applySymmetryToBonds = applySymmetryToBonds;
this.bondCount0 = this.asc.bondCount;
this.firstAtom = this.asc.getLastAtomSetAtomIndex();
var atomMax = this.asc.ac;
var ht =  new java.util.Hashtable();
var nChain = 0;
var atoms = this.asc.atoms;
var addBonds = (this.bondCount0 > this.asc.bondIndex0 && applySymmetryToBonds);
switch (particleMode) {
case 1:
for (var i = atomMax; --i >= this.firstAtom; ) {
var id = Integer.$valueOf(atoms[i].chainID);
var bs = ht.get(id);
if (bs == null) {
nChain++;
ht.put(id, bs =  new JU.BS());
}bs.set(i);
}
this.asc.bsAtoms =  new JU.BS();
for (var i = 0; i < nChain; i++) {
this.asc.bsAtoms.set(atomMax + i);
var a =  new J.adapter.smarter.Atom();
a.set(0, 0, 0);
a.radius = 16;
this.asc.addAtom(a);
}
var ichain = 0;
for (var e, $e = ht.entrySet().iterator (); $e.hasNext()&& ((e = $e.next ()) || true);) {
var a = atoms[atomMax + ichain++];
var bs = e.getValue();
for (var i = bs.nextSetBit(0); i >= 0; i = bs.nextSetBit(i + 1)) a.add(atoms[i]);

a.scale(1 / bs.cardinality());
a.atomName = "Pt" + ichain;
a.chainID = e.getKey().intValue();
}
this.firstAtom = atomMax;
atomMax += nChain;
addBonds = false;
break;
case 2:
this.asc.bsAtoms =  new JU.BS();
this.asc.bsAtoms.set(atomMax);
var a = atoms[atomMax] =  new J.adapter.smarter.Atom();
a.set(0, 0, 0);
for (var i = atomMax; --i >= this.firstAtom; ) a.add(atoms[i]);

a.scale(1 / (atomMax - this.firstAtom));
a.atomName = "Pt";
a.radius = 16;
this.asc.addAtom(a);
this.firstAtom = atomMax++;
addBonds = false;
break;
}
var assemblyIdAtoms = thisBiomolecule.get("asemblyIdAtoms");
if (filter.indexOf("#<") >= 0) {
len = Math.min(len, JU.PT.parseInt(filter.substring(filter.indexOf("#<") + 2)) - 1);
filter = JU.PT.rep(filter, "#<", "_<");
}var maxChain = 0;
for (var iAtom = this.firstAtom; iAtom < atomMax; iAtom++) {
atoms[iAtom].bsSymmetry =  new JU.BS();
var chainID = atoms[iAtom].chainID;
if (chainID > maxChain) maxChain = chainID;
}
var bsAtoms = this.asc.bsAtoms;
var atomMap = (addBonds ?  Clazz.newIntArray (this.asc.ac, 0) : null);
for (var imt = (biomtchains == null ? 1 : 0); imt < len; imt++) {
if (filter.indexOf("!#") >= 0) {
if (filter.indexOf("!#" + (imt + 1) + ";") >= 0) continue;
} else if (filter.indexOf("#") >= 0 && filter.indexOf("#" + (imt + 1) + ";") < 0) {
continue;
}var mat = biomts.get(imt);
var notIdentity = !mat.equals(this.mident);
var chains = (biomtchains == null ? null : biomtchains.get(imt));
if (chains != null && assemblyIdAtoms != null) {
bsAtoms =  new JU.BS();
for (var e, $e = assemblyIdAtoms.entrySet().iterator (); $e.hasNext()&& ((e = $e.next ()) || true);) if (chains.indexOf(":" + e.getKey() + ";") >= 0) bsAtoms.or(e.getValue());

if (this.asc.bsAtoms != null) bsAtoms.and(this.asc.bsAtoms);
chains = null;
}var lastID = -1;
var id;
var skipping = false;
for (var iAtom = this.firstAtom; iAtom < atomMax; iAtom++) {
if (bsAtoms != null) {
skipping = !bsAtoms.get(iAtom);
} else if (chains != null && (id = atoms[iAtom].chainID) != lastID) {
skipping = (chains.indexOf(":" + this.acr.vwr.getChainIDStr(lastID = id) + ";") < 0);
}if (skipping) continue;
try {
var atomSite = atoms[iAtom].atomSite;
var atom1;
if (addBonds) atomMap[atomSite] = this.asc.ac;
atom1 = this.asc.newCloneAtom(atoms[iAtom]);
atom1.bondingRadius = imt;
this.asc.atomSymbolicMap.put("" + atom1.atomSerial, atom1);
if (this.asc.bsAtoms != null) this.asc.bsAtoms.set(atom1.index);
atom1.atomSite = atomSite;
if (notIdentity) mat.rotTrans(atom1);
atom1.bsSymmetry = JU.BSUtil.newAndSetBit(imt);
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
this.asc.errorMessage = "appendAtomCollection error: " + e;
} else {
throw e;
}
}
}
if (notIdentity) this.symmetry.addBioMoleculeOperation(mat, false);
if (addBonds) {
for (var bondNum = this.asc.bondIndex0; bondNum < this.bondCount0; bondNum++) {
var bond = this.asc.bonds[bondNum];
var iAtom1 = atomMap[atoms[bond.atomIndex1].atomSite];
var iAtom2 = atomMap[atoms[bond.atomIndex2].atomSite];
this.asc.addNewBondWithOrder(iAtom1, iAtom2, bond.order);
}
}}
if (biomtchains != null) {
if (this.asc.bsAtoms == null) this.asc.bsAtoms = JU.BSUtil.newBitSet2(0, this.asc.ac);
this.asc.bsAtoms.clearBits(this.firstAtom, atomMax);
if (particleMode == 0) {
if (fixBMChains != -1) {
var assignABC = (fixBMChains != 0);
var bsChains = (assignABC ?  new JU.BS() : null);
atoms = this.asc.atoms;
var firstNew = 0;
if (assignABC) {
firstNew = (fixBMChains < 0 ? Math.max(-fixBMChains, maxChain + 1) : Math.max(maxChain + fixBMChains, 65));
bsChains.setBits(0, firstNew - 1);
bsChains.setBits(91, 97);
bsChains.setBits(123, 200);
}var bsAll = (this.asc.structureCount == 1 ? this.asc.structures[0].bsAll : null);
var chainMap =  new java.util.Hashtable();
var knownMap =  new java.util.Hashtable();
var knownAtomMap = (bsAll == null ? null :  new java.util.Hashtable());
var lastKnownAtom = null;
for (var i = atomMax, n = this.asc.ac; i < n; i++) {
var ic = atoms[i].chainID;
var isym = atoms[i].bsSymmetry.nextSetBit(0);
var ch0 = this.acr.vwr.getChainIDStr(ic);
var ch = (isym == 0 ? ch0 : ch0 + sep + isym);
var known = chainMap.get(ch);
if (known == null) {
if (assignABC && isym != 0) {
var pt = (firstNew < 200 ? bsChains.nextClearBit(firstNew) : 200);
if (pt < 200) {
bsChains.set(pt);
known = Integer.$valueOf(this.acr.vwr.getChainID("" + String.fromCharCode(pt), true));
firstNew = pt;
} else {
}}if (known == null) known = Integer.$valueOf(this.acr.vwr.getChainID(ch, true));
if (ch !== ch0) {
knownMap.put(known, Integer.$valueOf(ic));
if (bsAll != null) {
if (lastKnownAtom != null) lastKnownAtom[1] = i;
knownAtomMap.put(known, lastKnownAtom =  Clazz.newIntArray(-1, [i, n]));
}}chainMap.put(ch, known);
}atoms[i].chainID = known.intValue();
}
if (this.asc.structureCount > 0) {
var strucs = this.asc.structures;
var nStruc = this.asc.structureCount;
for (var e, $e = knownMap.entrySet().iterator (); $e.hasNext()&& ((e = $e.next ()) || true);) {
var known = e.getKey();
var ch1 = known.intValue();
var ch0 = e.getValue().intValue();
for (var i = 0; i < nStruc; i++) {
var s = strucs[i];
if (s.bsAll != null) {
} else if (s.startChainID == s.endChainID) {
if (s.startChainID == ch0) {
var s1 = s.clone();
s1.startChainID = s1.endChainID = ch1;
this.asc.addStructure(s1);
}} else {
System.err.println("XtalSymmetry not processing biomt chain structure " + this.acr.vwr.getChainIDStr(ch0) + " to " + this.acr.vwr.getChainIDStr(ch1));
}}
}
}}var vConnect = this.asc.getAtomSetAuxiliaryInfoValue(-1, "PDB_CONECT_bonds");
if (!addBonds && vConnect != null) {
for (var i = vConnect.size(); --i >= 0; ) {
var bond = vConnect.get(i);
var a = this.asc.getAtomFromName("" + bond[0]);
var b = this.asc.getAtomFromName("" + bond[1]);
if (a != null && b != null && a.bondingRadius != b.bondingRadius && (bsAtoms == null || bsAtoms.get(a.index) && bsAtoms.get(b.index)) && a.distanceSquared(b) > 25.0) {
vConnect.removeItemAt(i);
System.out.println("XtalSymmetry: long interchain bond removed for @" + a.atomSerial + "-@" + b.atomSerial);
}}
}}for (var i = atomMax, n = this.asc.ac; i < n; i++) {
this.asc.atoms[i].bondingRadius = NaN;
}
}this.noSymmetryCount = atomMax - this.firstAtom;
this.finalizeSymmetry(this.symmetry);
this.setCurrentModelInfo(len, null, null);
this.reset();
}, "java.util.Map,~B,~S");
Clazz.defineMethod(c$, "getBaseSymmetry", 
function(){
return (this.baseSymmetry == null ? this.symmetry : this.baseSymmetry);
});
Clazz.defineMethod(c$, "getOverallSpan", 
function(){
return (this.maxXYZ0 == null ? JU.V3.new3(this.maxXYZ.x - this.minXYZ.x, this.maxXYZ.y - this.minXYZ.y, this.maxXYZ.z - this.minXYZ.z) : JU.V3.newVsub(this.maxXYZ0, this.minXYZ0));
});
Clazz.defineMethod(c$, "getFileSymmetry", 
function(){
return (this.symmetry == null ? (this.symmetry =  new J.adapter.smarter.XtalSymmetry.FileSymmetry()) : this.symmetry);
});
c$.isWithinSupercell = Clazz.defineMethod(c$, "isWithinSupercell", 
function(ndims, pt, minX, maxX, minY, maxY, minZ, maxZ, slop){
return (pt.x > minX - slop && pt.x < maxX + slop && (ndims < 2 || pt.y > minY - slop && pt.y < maxY + slop) && (ndims < 3 || pt.z > minZ - slop && pt.z < maxZ + slop));
}, "~N,JU.P3,~N,~N,~N,~N,~N,~N,~N");
Clazz.defineMethod(c$, "set", 
function(reader){
this.acr = reader;
this.asc = reader.asc;
this.getFileSymmetry();
return this;
}, "J.adapter.smarter.AtomSetCollectionReader");
Clazz.defineMethod(c$, "setLatticeParameter", 
function(latt){
this.symmetry.setSpaceGroup(this.doNormalize);
this.symmetry.setLattice(latt);
}, "~N");
Clazz.defineMethod(c$, "addSpaceGroupOperation", 
function(xyz, andSetLattice){
this.symmetry.setSpaceGroup(this.doNormalize);
if (andSetLattice && this.symmetry.getSpaceGroupOperationCount() == 1) this.setLatticeCells();
return this.symmetry.addSpaceGroupOperation(xyz, 0);
}, "~S,~B");
Clazz.defineMethod(c$, "setSymmetryFromAuditBlock", 
function(symmetry){
return (this.symmetry = symmetry);
}, "J.adapter.smarter.XtalSymmetry.FileSymmetry");
Clazz.defineMethod(c$, "applySymmetryFromReader", 
function(readerSymmetry){
this.asc.setCoordinatesAreFractional(this.acr.iHaveFractionalCoordinates);
this.setAtomSetSpaceGroupName(this.acr.sgName);
this.symmetryRange = this.acr.symmetryRange;
this.asc.setInfo("symmetryRange", Float.$valueOf(this.symmetryRange));
if (this.acr.doConvertToFractional || this.acr.fileCoordinatesAreFractional) {
this.setLatticeCells();
var doApplySymmetry = true;
if (this.acr.ignoreFileSpaceGroupName || !this.acr.iHaveSymmetryOperators) {
if (!this.acr.merging || readerSymmetry == null) readerSymmetry =  new J.adapter.smarter.XtalSymmetry.FileSymmetry();
if (this.acr.unitCellParams[0] == 0 && this.acr.unitCellParams[2] == 0) {
JU.SimpleUnitCell.fillParams(null, null, null, this.acr.unitCellParams);
}doApplySymmetry = readerSymmetry.createSpaceGroup(this.acr.desiredSpaceGroupIndex, (this.acr.sgName.indexOf("!") >= 0 ? "P1" : this.acr.sgName), this.acr.unitCellParams, this.acr.modDim);
} else {
this.acr.doPreSymmetry(doApplySymmetry);
readerSymmetry = null;
}this.packingRange = this.acr.getPackingRangeValue(0);
if (doApplySymmetry) {
if (readerSymmetry != null) this.getFileSymmetry().setSpaceGroupTo(readerSymmetry.getSpaceGroup());
this.applySymmetryLattice();
if (readerSymmetry != null && this.filterSymop == null) this.setAtomSetSpaceGroupName(readerSymmetry.getSpaceGroupName());
} else {
this.setUnitCellSafely();
}}if (this.acr.iHaveFractionalCoordinates && this.acr.merging && readerSymmetry != null) {
var atoms = this.asc.atoms;
for (var i = this.asc.getLastAtomSetAtomIndex(), n = this.asc.ac; i < n; i++) readerSymmetry.toCartesian(atoms[i], true);

this.asc.setCoordinatesAreFractional(false);
this.acr.addVibrations = false;
}return this.symmetry;
}, "J.adapter.smarter.XtalSymmetry.FileSymmetry");
Clazz.defineMethod(c$, "finalizeMoments", 
function(spinFrameExt, htCellTypes){
this.getFileSymmetry().preSymmetryFinalizeMoments(this.acr, this.asc, spinFrameExt, htCellTypes);
}, "java.util.Map,java.util.Map");
Clazz.defineMethod(c$, "setMagneticMoments", 
function(isCartesian){
return (this.asc.iSet < 0 || !this.acr.vibsFractional ? 0 : this.getBaseSymmetry().postSymmetrySetMagneticMoments(this.asc, isCartesian));
}, "~B");
Clazz.defineMethod(c$, "setTensors", 
function(andSetBFactor){
var n = this.asc.ac;
for (var i = this.asc.getLastAtomSetAtomIndex(); i < n; i++) {
var a = this.asc.atoms[i];
if (a.anisoBorU == null) continue;
a.addTensor(this.symmetry.getTensor(this.acr.vwr, a.anisoBorU), null, false);
if (Float.isNaN(a.bfactor) && andSetBFactor) a.bfactor = a.anisoBorU[7] * 100;
a.anisoBorU = null;
}
}, "~B");
Clazz.defineMethod(c$, "adjustRangeMinMax", 
function(oabc){
this.symmetry.adjustRangeMinMax(oabc, (this.acr.forcePacked ? this.packingRange : NaN), this.minXYZ, this.maxXYZ, this.rmin, this.rmax, this.minXYZ, this.maxXYZ);
}, "~A");
Clazz.defineMethod(c$, "applyAllSymmetry", 
function(ms, bsAtoms){
if (this.asc.ac == 0 || bsAtoms != null && bsAtoms.isEmpty()) return;
var n = this.noSymmetryCount = this.asc.baseSymmetryAtomCount > 0 ? this.asc.baseSymmetryAtomCount : bsAtoms == null ? this.asc.getLastAtomSetAtomCount() : this.asc.ac - bsAtoms.nextSetBit(this.asc.getLastAtomSetAtomIndex());
this.asc.setTensors();
this.applySymmetryToBonds = this.acr.applySymmetryToBonds;
this.doPackUnitCell = this.acr.doPackUnitCell && !this.applySymmetryToBonds;
this.bondCount0 = this.asc.bondCount;
this.ndims = JU.SimpleUnitCell.getDimensionFromParams(this.acr.unitCellParams);
this.finalizeSymmetry(this.symmetry);
var operationCount = this.symmetry.getSpaceGroupOperationCount();
var excludedOps = (this.acr.thisBiomolecule == null ? null :  new JU.BS());
this.checkNearAtoms = this.acr.checkNearAtoms || excludedOps != null;
JU.SimpleUnitCell.setMinMaxLatticeParameters(this.ndims, this.minXYZ, this.maxXYZ, 0);
this.latticeOp = this.symmetry.getLatticeOp();
this.crystalReaderLatticeOpsOnly = (this.asc.crystalReaderLatticeOpsOnly && this.latticeOp >= 0);
if (this.doCentroidUnitCell) this.asc.setInfo("centroidMinMax",  Clazz.newIntArray(-1, [this.minXYZ.x, this.minXYZ.y, this.minXYZ.z, this.maxXYZ.x, this.maxXYZ.y, this.maxXYZ.z, (this.centroidPacked ? Clazz.floatToInt(this.packingRange * 100) : 0)]));
if (this.doCentroidUnitCell || this.acr.doPackUnitCell || this.symmetryRange != 0 && this.maxXYZ.x - this.minXYZ.x == 1 && this.maxXYZ.y - this.minXYZ.y == 1 && this.maxXYZ.z - this.minXYZ.z == 1) {
this.minXYZ0 = JU.P3.new3(this.minXYZ.x, this.minXYZ.y, this.minXYZ.z);
this.maxXYZ0 = JU.P3.new3(this.maxXYZ.x, this.maxXYZ.y, this.maxXYZ.z);
if (ms != null) {
ms.setMinMax0(this.minXYZ0, this.maxXYZ0);
this.minXYZ.set(Clazz.floatToInt(this.minXYZ0.x), Clazz.floatToInt(this.minXYZ0.y), Clazz.floatToInt(this.minXYZ0.z));
this.maxXYZ.set(Clazz.floatToInt(this.maxXYZ0.x), Clazz.floatToInt(this.maxXYZ0.y), Clazz.floatToInt(this.maxXYZ0.z));
}switch (this.ndims) {
case 3:
this.minXYZ.z--;
this.maxXYZ.z++;
case 2:
this.minXYZ.y--;
this.maxXYZ.y++;
case 1:
this.minXYZ.x--;
this.maxXYZ.x++;
}
}var nCells = (this.maxXYZ.x - this.minXYZ.x) * (this.maxXYZ.y - this.minXYZ.y) * (this.maxXYZ.z - this.minXYZ.z);
var nsym = n * (this.crystalReaderLatticeOpsOnly ? 4 : operationCount);
var cartesianCount = (this.checkNearAtoms || this.acr.thisBiomolecule != null ? nsym * nCells : this.symmetryRange > 0 ? nsym : 1);
var cartesians =  new Array(cartesianCount);
var atoms = this.asc.atoms;
for (var i = 0; i < n; i++) atoms[this.firstAtom + i].bsSymmetry = JU.BS.newN(operationCount * (nCells + 1));

var pt = 0;
this.unitCellTranslations =  new Array(nCells);
var iCell = 0;
var cell555Count = 0;
var absRange = Math.abs(this.symmetryRange);
var checkCartesianRange = (this.symmetryRange != 0);
var checkRangeNoSymmetry = (this.symmetryRange < 0);
var checkRange111 = (this.symmetryRange > 0);
if (checkCartesianRange) {
this.rmin.x = this.rmin.y = this.rmin.z = 3.4028235E38;
this.rmax.x = this.rmax.y = this.rmax.z = -3.4028235E38;
}var sym = this.symmetry;
var lastSymmetry = sym;
this.checkAll = (this.crystalReaderLatticeOpsOnly || this.asc.atomSetCount == 1 && this.checkNearAtoms && this.latticeOp >= 0);
var lstNCS = this.acr.lstNCS;
if (lstNCS != null && lstNCS.get(0).m33 == 0) {
var nOp = sym.getSpaceGroupOperationCount();
var nn = lstNCS.size();
for (var i = nn; --i >= 0; ) {
var m = lstNCS.get(i);
m.m33 = 1;
sym.toFractionalM(m);
}
for (var i = 1; i < nOp; i++) {
var m1 = sym.getSpaceGroupOperation(i);
for (var j = 0; j < nn; j++) {
var m = JU.M4.newM4(lstNCS.get(j));
m.mul2(m1, m);
if (this.doNormalize && this.noSymmetryCount > 0) JS.SymmetryOperation.normalizeOperationToCentroid(3, m, atoms, this.firstAtom, this.noSymmetryCount);
lstNCS.addLast(m);
}
}
}var pttemp = null;
var op = sym.getSpaceGroupOperation(0);
if (this.doPackUnitCell) {
pttemp =  new JU.P3();
this.ptOffset.set(0, 0, 0);
}var atomMap = (this.bondCount0 > this.asc.bondIndex0 && this.applySymmetryToBonds ?  Clazz.newIntArray (n, 0) : null);
var unitCells =  Clazz.newIntArray (nCells, 0);
for (var tx = this.minXYZ.x; tx < this.maxXYZ.x; tx++) {
for (var ty = this.minXYZ.y; ty < this.maxXYZ.y; ty++) {
for (var tz = this.minXYZ.z; tz < this.maxXYZ.z; tz++) {
this.unitCellTranslations[iCell] = JU.V3.new3(tx, ty, tz);
unitCells[iCell++] = 555 + tx * 100 + ty * 10 + tz;
if (tx != 0 || ty != 0 || tz != 0 || cartesians.length == 0) continue;
for (pt = 0; pt < n; pt++) {
var atom = atoms[this.firstAtom + pt];
if (ms != null) {
sym = ms.getAtomSymmetry(atom, this.symmetry);
if (sym !== lastSymmetry) {
if (sym.getSpaceGroupOperationCount() == 0) this.finalizeSymmetry(sym);
lastSymmetry = sym;
op = sym.getSpaceGroupOperation(0);
}}var c = JU.P3.newP(atom);
op.rotTrans(c);
sym.toCartesian(c, false);
if (this.doPackUnitCell) {
sym.toUnitCellRnd(c, this.ptOffset);
pttemp.setT(c);
sym.toFractional(pttemp, false);
this.acr.fixFloatPt(pttemp, 100000.0);
if (bsAtoms == null) atom.setT(pttemp);
 else if (atom.distance(pttemp) < 1.0E-4) bsAtoms.set(atom.index);
 else {
bsAtoms.clear(atom.index);
continue;
}}if (bsAtoms != null) atom.bsSymmetry.clearAll();
atom.bsSymmetry.set(iCell * operationCount);
atom.bsSymmetry.set(0);
if (checkCartesianRange) JS.UnitCell.setSymmetryMinMax(c, this.rmin, this.rmax);
if (pt < cartesianCount) cartesians[pt] = c;
}
if (checkRangeNoSymmetry) {
this.rmin.x -= absRange;
this.rmin.y -= absRange;
this.rmin.z -= absRange;
this.rmax.x += absRange;
this.rmax.y += absRange;
this.rmax.z += absRange;
}cell555Count = pt = this.symmetryAddAtoms(0, 0, 0, 0, pt, iCell * operationCount, cartesians, ms, excludedOps, atomMap);
}
}
}
if (checkRange111) {
this.rmin.x -= absRange;
this.rmin.y -= absRange;
this.rmin.z -= absRange;
this.rmax.x += absRange;
this.rmax.y += absRange;
this.rmax.z += absRange;
}iCell = 0;
for (var tx = this.minXYZ.x; tx < this.maxXYZ.x; tx++) {
for (var ty = this.minXYZ.y; ty < this.maxXYZ.y; ty++) {
for (var tz = this.minXYZ.z; tz < this.maxXYZ.z; tz++) {
iCell++;
if (tx != 0 || ty != 0 || tz != 0) pt = this.symmetryAddAtoms(tx, ty, tz, cell555Count, pt, iCell * operationCount, cartesians, ms, excludedOps, atomMap);
}
}
}
if (iCell * n == this.asc.ac - this.firstAtom) this.duplicateAtomProperties(iCell);
this.setCurrentModelInfo(n, sym, unitCells);
this.unitCellParams = null;
this.reset();
}, "J.adapter.smarter.MSInterface,JU.BS");
Clazz.defineMethod(c$, "applyRangeSymmetry", 
function(bsAtoms){
var range = this.acr.fillRange;
bsAtoms = this.updateBSAtoms();
this.acr.forcePacked = true;
this.doPackUnitCell = false;
var minXYZ2 = JU.P3i.new3(this.minXYZ.x, this.minXYZ.y, this.minXYZ.z);
var maxXYZ2 = JU.P3i.new3(this.maxXYZ.x, this.maxXYZ.y, this.maxXYZ.z);
var oabc =  new Array(4);
for (var i = 0; i < 4; i++) oabc[i] = JU.P3.newP(range[i]);

this.setUnitCellSafely();
this.adjustRangeMinMax(oabc);
var superSymmetry =  new J.adapter.smarter.XtalSymmetry.FileSymmetry();
superSymmetry.getUnitCell(this.acr.fillRange, false, null);
this.applyAllSymmetry(this.acr.ms, bsAtoms);
var pt0 =  new JU.P3();
var atoms = this.asc.atoms;
for (var i = this.asc.ac; --i >= this.firstAtom; ) {
pt0.setT(atoms[i]);
this.symmetry.toCartesian(pt0, false);
superSymmetry.toFractional(pt0, false);
if (this.acr.noPack ? !J.adapter.smarter.XtalSymmetry.removePacking(this.ndims, pt0, minXYZ2.x, maxXYZ2.x, minXYZ2.y, maxXYZ2.y, minXYZ2.z, maxXYZ2.z, this.packingRange) : !J.adapter.smarter.XtalSymmetry.isWithinSupercell(this.ndims, pt0, minXYZ2.x, maxXYZ2.x, minXYZ2.y, maxXYZ2.y, minXYZ2.z, maxXYZ2.z, this.packingRange)) bsAtoms.clear(i);
}
}, "JU.BS");
Clazz.defineMethod(c$, "applySuperSymmetry", 
function(supercell, bsAtoms, iAtomFirst, oabc, pt0, vabc, slop){
this.asc.setGlobalBoolean(7);
var doPack0 = this.doPackUnitCell;
this.doPackUnitCell = doPack0;
this.applyAllSymmetry(this.acr.ms, null);
this.doPackUnitCell = doPack0;
var atoms = this.asc.atoms;
var atomCount = this.asc.ac;
for (var i = iAtomFirst; i < atomCount; i++) {
this.symmetry.toCartesian(atoms[i], true);
bsAtoms.set(i);
}
this.asc.setCurrentModelInfo("unitcell_conventional", this.symmetry.getV0abc("a,b,c", null));
var va = vabc[0];
var vb = vabc[1];
var vc = vabc[2];
this.symmetry =  new J.adapter.smarter.XtalSymmetry.FileSymmetry();
this.setUnitCell( Clazz.newFloatArray(-1, [0, 0, 0, 0, 0, 0, va.x, va.y, va.z, vb.x, vb.y, vb.z, vc.x, vc.y, vc.z, 0, 0, 0, 0, 0, 0, NaN, NaN, NaN, NaN, NaN, slop]), null, null);
var name = oabc == null || supercell == null ? "P1" : "cell=" + supercell;
this.setAtomSetSpaceGroupName(name);
this.symmetry.setSpaceGroupName(name);
this.symmetry.setSpaceGroup(this.doNormalize);
this.symmetry.addSpaceGroupOperation("x,y,z", 0);
if (pt0 != null && pt0.length() == 0) pt0 = null;
if (pt0 != null) this.symmetry.toFractional(pt0, true);
for (var i = iAtomFirst; i < atomCount; i++) {
this.symmetry.toFractional(atoms[i], true);
if (pt0 != null) atoms[i].sub(pt0);
}
this.asc.haveAnisou = false;
this.asc.setCurrentModelInfo("matUnitCellOrientation", null);
}, "~S,JU.BS,~N,~A,JU.P3,~A,~N");
Clazz.defineMethod(c$, "applySymmetryLattice", 
function(){
if (!this.asc.coordinatesAreFractional || this.symmetry.getSpaceGroup() == null) return;
var maxX = this.latticeCells[0];
var maxY = this.latticeCells[1];
var maxZ = Math.abs(this.latticeCells[2]);
var kcode = this.latticeCells[3];
this.firstAtom = this.asc.getLastAtomSetAtomIndex();
var bsAtoms = this.asc.bsAtoms;
if (bsAtoms != null) {
this.updateBSAtoms();
this.firstAtom = bsAtoms.nextSetBit(this.firstAtom);
}this.rmin = JU.P3.new3(3.4028235E38, 3.4028235E38, 3.4028235E38);
this.rmax = JU.P3.new3(-3.4028235E38, -3.4028235E38, -3.4028235E38);
if (this.acr.latticeType == null) this.acr.latticeType = "" + this.symmetry.getLatticeType();
if (this.acr.isPrimitive) {
this.asc.setCurrentModelInfo("isprimitive", Boolean.TRUE);
if (!"P".equals(this.acr.latticeType) || this.acr.primitiveToCrystal != null) {
this.asc.setCurrentModelInfo("unitcell_conventional", this.symmetry.getConventionalUnitCell(this.acr.latticeType, this.acr.primitiveToCrystal));
}}this.setUnitCellSafely();
this.asc.setCurrentModelInfo("f2c", this.symmetry.getUnitCellF2C());
var s = this.symmetry.getSpaceGroupTitle();
if (s.indexOf("--") < 0) this.asc.setCurrentModelInfo("f2cTitle", s);
this.asc.setCurrentModelInfo("f2cParams", this.symmetry.getUnitCellParams());
if (this.acr.latticeType != null) {
this.asc.setCurrentModelInfo("latticeType", this.acr.latticeType);
if ((typeof(this.acr.fillRange)=='string')) {
var range = this.setLatticeRange(this.acr.latticeType, this.acr.fillRange);
if (range == null) {
this.acr.appendLoadNote(this.acr.fillRange + " symmetry could not be implemented");
this.acr.fillRange = null;
} else {
this.acr.fillRange = range;
}}}this.baseSymmetry = this.symmetry;
if (this.acr.fillRange != null) {
this.setMinMax(this.ndims, kcode, maxX, maxY, maxZ);
this.applyRangeSymmetry(bsAtoms);
return;
}var oabc = null;
var slop = 1e-6;
this.baseSymmetry.nSpins = 0;
var supercell = this.acr.strSupercell;
var isSuper = (supercell != null && supercell.indexOf(",") >= 0);
var matSuper = null;
var pt0 = null;
var vabc =  new Array(3);
if (isSuper) {
matSuper =  new JU.M4();
if (this.mident == null) this.mident =  new JU.M4();
this.setUnitCellSafely();
oabc = this.symmetry.getV0abc(supercell, matSuper);
matSuper.transpose33();
if (oabc != null && !matSuper.equals(this.mident)) {
this.setMinMax(this.ndims, kcode, maxX, maxY, maxZ);
pt0 = JU.P3.newP(oabc[0]);
vabc[0] = JU.V3.newV(oabc[1]);
vabc[1] = JU.V3.newV(oabc[2]);
vabc[2] = JU.V3.newV(oabc[3]);
this.adjustRangeMinMax(oabc);
}}var iAtomFirst = this.asc.getLastAtomSetAtomIndex();
if (bsAtoms != null) iAtomFirst = bsAtoms.nextSetBit(iAtomFirst);
if (this.rmin.x == 3.4028235E38) {
supercell = null;
oabc = null;
} else {
bsAtoms = this.updateBSAtoms();
slop = this.symmetry.getPrecision();
this.applySuperSymmetry(supercell, bsAtoms, iAtomFirst, oabc, pt0, vabc, slop);
}this.setMinMax(this.ndims, kcode, maxX, maxY, maxZ);
if (oabc == null) {
this.applyAllSymmetry(this.acr.ms, bsAtoms);
if (!this.acr.noPack && (!this.applySymmetryToBonds || !this.acr.doPackUnitCell)) {
return;
}this.setMinMax(this.ndims, kcode, maxX, maxY, maxZ);
}if (this.acr.forcePacked || this.acr.doPackUnitCell || this.acr.noPack) {
this.trimToUnitCell(iAtomFirst);
}this.updateSupercellAtomSites(matSuper, bsAtoms, slop);
});
Clazz.defineMethod(c$, "duplicateAtomProperties", 
function(nTimes){
var p = this.asc.getAtomSetAuxiliaryInfoValue(-1, "atomProperties");
if (p != null) for (var entry, $entry = p.entrySet().iterator (); $entry.hasNext()&& ((entry = $entry.next ()) || true);) {
var key = entry.getKey();
var val = entry.getValue();
if ((typeof(val)=='string')) {
var data = val;
var s =  new JU.SB();
for (var i = nTimes; --i >= 0; ) s.append(data);

p.put(key, s.toString());
} else {
var f = val;
var fnew =  Clazz.newFloatArray (f.length * nTimes, 0);
for (var i = nTimes; --i >= 0; ) System.arraycopy(f, 0, fnew, i * f.length, f.length);

}}
}, "~N");
Clazz.defineMethod(c$, "finalizeSymmetry", 
function(symmetry){
var name = this.asc.getAtomSetAuxiliaryInfoValue(-1, "spaceGroup");
symmetry.setFinalOperations(this.ndims, name, this.asc.atoms, this.firstAtom, this.noSymmetryCount, this.doNormalize, this.filterSymop);
if (this.filterSymop != null || name == null || name.equals("unspecified!")) {
this.setAtomSetSpaceGroupName(symmetry.getUnitCellDisplayName());
}if (this.unitCellParams != null || Float.isNaN(this.acr.unitCellParams[0])) return;
if (symmetry.fixUnitCell(this.acr.unitCellParams)) {
this.acr.appendLoadNote("Unit cell parameters were adjusted to match space group!");
}this.setUnitCellSafely();
}, "J.adapter.smarter.XtalSymmetry.FileSymmetry");
c$.removePacking = Clazz.defineMethod(c$, "removePacking", 
function(ndims, pt, minX, maxX, minY, maxY, minZ, maxZ, slop){
return (pt.x > minX - slop && pt.x < maxX - slop && (ndims < 2 || pt.y > minY - slop && pt.y < maxY - slop) && (ndims < 3 || pt.z > minZ - slop && pt.z < maxZ - slop));
}, "~N,JU.P3,~N,~N,~N,~N,~N,~N,~N");
Clazz.defineMethod(c$, "reset", 
function(){
this.asc.coordinatesAreFractional = false;
this.asc.setCurrentModelInfo("hasSymmetry", Boolean.TRUE);
this.asc.setGlobalBoolean(1);
});
Clazz.defineMethod(c$, "setAtomSetSpaceGroupName", 
function(spaceGroupName){
this.symmetry.setSpaceGroupName(spaceGroupName);
var s = spaceGroupName + "";
this.asc.setCurrentModelInfo("f2cTitle", s);
this.asc.setCurrentModelInfo("spaceGroup", s);
}, "~S");
Clazz.defineMethod(c$, "setCurrentModelInfo", 
function(n, sym, unitCells){
if (sym == null) {
this.asc.setCurrentModelInfo("presymmetryAtomCount", Integer.$valueOf(this.noSymmetryCount));
this.asc.setCurrentModelInfo("biosymmetryCount", Integer.$valueOf(n));
this.asc.setCurrentModelInfo("bioSymmetry", this.symmetry);
} else {
this.asc.setCurrentModelInfo("fileSymmetry", this.symmetry);
this.asc.setCurrentModelInfo("presymmetryAtomCount", Integer.$valueOf(n));
this.asc.setCurrentModelInfo("latticeDesignation", sym.getLatticeDesignation());
this.asc.setCurrentModelInfo("ML_unitCellRange", unitCells);
if (this.acr.isSUPERCELL) this.asc.setCurrentModelInfo("supercell", this.acr.strSupercell);
}this.asc.setCurrentModelInfo("presymmetryAtomIndex", Integer.$valueOf(this.firstAtom));
var operationCount = this.symmetry.getSpaceGroupOperationCount();
if (operationCount > 0) {
this.asc.setCurrentModelInfo("symmetryOperations", this.symmetry.getSymopList(this.doNormalize));
this.asc.setCurrentModelInfo("symOpsTemp", this.symmetry.getSymmetryOperations());
}this.asc.setCurrentModelInfo("symmetryCount", Integer.$valueOf(operationCount));
this.asc.setCurrentModelInfo("latticeType", this.acr.latticeType == null ? "P" : this.acr.latticeType);
this.asc.setCurrentModelInfo("intlTableNo", this.symmetry.getIntTableNumber());
this.asc.setCurrentModelInfo("intlTableIndex", this.symmetry.getSpaceGroupInfoObj("itaIndex", null, false, false));
this.asc.setCurrentModelInfo("intlTableTransform", this.symmetry.getSpaceGroupInfoObj("itaTransform", null, false, false));
this.asc.setCurrentModelInfo("intlTableJmolId", this.symmetry.getSpaceGroupJmolId());
this.asc.setCurrentModelInfo("spaceGroupIndex", Integer.$valueOf(this.symmetry.getSpaceGroupIndex()));
this.asc.setCurrentModelInfo("spaceGroupTitle", this.symmetry.getSpaceGroupTitle());
if (this.acr.sgName == null || this.acr.sgName.indexOf("?") >= 0 || this.acr.sgName.indexOf("!") >= 0) this.setAtomSetSpaceGroupName(this.acr.sgName = this.symmetry.getSpaceGroupName());
}, "~N,J.adapter.smarter.XtalSymmetry.FileSymmetry,~A");
Clazz.defineMethod(c$, "setCurrentModelUCInfo", 
function(unitCellParams, unitCellOffset, matUnitCellOrientation){
if (unitCellParams != null) this.asc.setCurrentModelInfo("unitCellParams", unitCellParams);
if (unitCellOffset != null) this.asc.setCurrentModelInfo("unitCellOffset", unitCellOffset);
if (matUnitCellOrientation != null) this.asc.setCurrentModelInfo("matUnitCellOrientation", matUnitCellOrientation);
}, "~A,JU.P3,JU.M3");
Clazz.defineMethod(c$, "setLatticeCells", 
function(){
this.latticeCells = this.acr.latticeCells;
var isLatticeRange = (this.latticeCells[0] <= 555 && this.latticeCells[1] >= 555 && (this.latticeCells[2] == 0 || this.latticeCells[2] == 1 || this.latticeCells[2] == -1));
this.doNormalize = this.latticeCells[0] != 0 && (!isLatticeRange || this.latticeCells[2] == 1);
this.applySymmetryToBonds = this.acr.applySymmetryToBonds;
this.doPackUnitCell = this.acr.doPackUnitCell && !this.applySymmetryToBonds;
this.doCentroidUnitCell = this.acr.doCentroidUnitCell;
this.centroidPacked = this.acr.centroidPacked;
this.filterSymop = this.acr.filterSymop;
});
Clazz.defineMethod(c$, "setLatticeRange", 
function(latticetype, rangeType){
var range = null;
var isRhombohedral = "R".equals(latticetype);
if (rangeType.equals("conventional")) {
range = this.symmetry.getConventionalUnitCell(latticetype, this.acr.primitiveToCrystal);
} else if (rangeType.equals("primitive")) {
range = this.symmetry.getUnitCellVectors();
this.symmetry.toFromPrimitive(true, latticetype.charAt(0), range, this.acr.primitiveToCrystal);
} else if (isRhombohedral && rangeType.equals("rhombohedral")) {
if (this.symmetry.getUnitCellInfoType(8) == 1) {
rangeType = "2/3a+1/3b+1/3c,-1/3a+1/3b+1/3c,-1/3a-2/3b+1/3c";
} else {
rangeType = null;
}} else if (isRhombohedral && rangeType.equals("trigonal")) {
if (this.symmetry.getUnitCellInfoType(9) == 1) {
rangeType = "a-b,b-c,a+b+c";
} else {
rangeType = null;
}} else if (rangeType.equals("spin")) {
rangeType = this.getBaseSymmetry().spinFrameStr;
} else if (rangeType.startsWith("unitcell_")) {
rangeType = this.asc.getAtomSetAuxiliaryInfoValue(-1, rangeType);
} else if (rangeType.indexOf(",") < 0 || rangeType.indexOf("a") < 0 || rangeType.indexOf("b") < 0 || rangeType.indexOf("c") < 0) {
rangeType = null;
}if (rangeType != null && range == null && (range = this.symmetry.getV0abc(rangeType, null)) == null) {
rangeType = null;
}if (rangeType == null) return null;
this.acr.addJmolScript("unitcell " + JU.PT.esc(rangeType));
return range;
}, "~S,~S");
Clazz.defineMethod(c$, "setMinMax", 
function(dim, kcode, maxX, maxY, maxZ){
this.minXYZ =  new JU.P3i();
this.maxXYZ = JU.P3i.new3(maxX, maxY, maxZ);
JU.SimpleUnitCell.setMinMaxLatticeParameters(dim, this.minXYZ, this.maxXYZ, kcode);
}, "~N,~N,~N,~N,~N");
Clazz.defineMethod(c$, "setUnitCell", 
function(info, matUnitCellOrientation, unitCellOffset){
this.unitCellParams =  Clazz.newFloatArray (info.length, 0);
for (var i = 0; i < info.length; i++) this.unitCellParams[i] = info[i];

this.asc.haveUnitCell = true;
if (this.asc.isTrajectory) {
if (this.trajectoryUnitCells == null) {
this.trajectoryUnitCells =  new JU.Lst();
this.asc.setInfo("unitCells", this.trajectoryUnitCells);
}this.trajectoryUnitCells.addLast(this.unitCellParams);
}this.asc.setGlobalBoolean(2);
this.getFileSymmetry().setUnitCellFromParams(this.unitCellParams, false, this.acr.cellSlop);
if (unitCellOffset != null) this.symmetry.setOffsetPt(unitCellOffset);
if (matUnitCellOrientation != null) this.symmetry.initializeOrientation(matUnitCellOrientation);
this.setCurrentModelUCInfo(this.unitCellParams, unitCellOffset, matUnitCellOrientation);
}, "~A,JU.M3,JU.P3");
Clazz.defineMethod(c$, "setUnitCellSafely", 
function(){
if (this.unitCellParams == null) this.setUnitCell(this.acr.unitCellParams, this.acr.matUnitCellOrientation, this.acr.unitCellOffset);
});
Clazz.defineMethod(c$, "symmetryAddAtoms", 
function(transX, transY, transZ, baseCount, pt, iCellOpPt, cartesians, ms, excludedOps, atomMap){
var isBaseCell = (baseCount == 0);
var addBonds = (atomMap != null);
if (this.doPackUnitCell) this.ptOffset.set(transX, transY, transZ);
var range2 = this.symmetryRange * this.symmetryRange;
var checkRangeNoSymmetry = (this.symmetryRange < 0);
var checkRange111 = (this.symmetryRange > 0);
var checkSymmetryMinMax = (isBaseCell && checkRange111);
checkRange111 = new Boolean (checkRange111 & !isBaseCell).valueOf();
var nOp = this.symmetry.getSpaceGroupOperationCount();
var lstNCS = this.acr.lstNCS;
var nNCS = (lstNCS == null ? 0 : lstNCS.size());
var nOperations = nOp + nNCS;
nNCS = Clazz.doubleToInt(nNCS / nOp);
var checkNearAtoms = (this.checkNearAtoms && (nOperations > 1 || this.doPackUnitCell));
var checkSymmetryRange = (checkRangeNoSymmetry || checkRange111);
var checkDistances = (checkNearAtoms || checkSymmetryRange);
var checkOps = (excludedOps != null);
var addCartesian = (checkNearAtoms || checkSymmetryMinMax);
var bsAtoms = (this.acr.isMolecular ? null : this.asc.bsAtoms);
var sym = this.symmetry;
if (checkRangeNoSymmetry) baseCount = this.noSymmetryCount;
var atomMax = this.firstAtom + this.noSymmetryCount;
var bondAtomMin = (this.asc.firstAtomToBond < 0 ? atomMax : this.asc.firstAtomToBond);
var pttemp =  new JU.P3();
var code = null;
var minCartDist2 = (checkOps ? 0.01 : 1.0E-4);
var subSystemId = '\u0000';
var j00 = (bsAtoms == null ? this.firstAtom : bsAtoms.nextSetBit(this.firstAtom));
out : for (var iSym = 0; iSym < nOperations; iSym++) {
if (isBaseCell && iSym == 0 || this.crystalReaderLatticeOpsOnly && iSym > 0 && (iSym % this.latticeOp) != 0 || excludedOps != null && excludedOps.get(iSym)) continue;
var pt0 = this.firstAtom + (checkNearAtoms ? pt : checkRange111 ? baseCount : 0);
var timeReversal = (iSym >= nOp ? 0 : this.asc.vibScale == 0 ? sym.getSpinOp(iSym) : this.asc.vibScale);
var i0 = Math.max(this.firstAtom, (bsAtoms == null ? 0 : bsAtoms.nextSetBit(0)));
var checkDistance = checkDistances;
var spt = (iSym >= nOp ? Clazz.doubleToInt((iSym - nOp) / nNCS) : iSym);
var cpt = spt + iCellOpPt;
for (var i = i0; i < atomMax; i++) {
var a = this.asc.atoms[i];
if (bsAtoms != null && !bsAtoms.get(i)) continue;
if (ms == null) {
sym.newSpaceGroupPoint(a, iSym, (iSym >= nOp ? lstNCS.get(iSym - nOp) : null), transX, transY, transZ, pttemp);
} else {
sym = ms.getAtomSymmetry(a, this.symmetry);
sym.newSpaceGroupPoint(a, iSym, null, transX, transY, transZ, pttemp);
code = sym.getSpaceGroupOperationCode(iSym);
if (code != null) {
subSystemId = code.charAt(0);
sym = ms.getSymmetryFromCode(code);
if (sym.getSpaceGroupOperationCount() == 0) this.finalizeSymmetry(sym);
}}this.acr.fixFloatPt(pttemp, 100000.0);
var c = JU.P3.newP(pttemp);
sym.toCartesian(c, false);
if (this.doPackUnitCell) {
sym.toUnitCellRnd(c, this.ptOffset);
pttemp.setT(c);
sym.toFractional(pttemp, false);
this.acr.fixFloatPt(pttemp, 100000.0);
if (!J.adapter.smarter.XtalSymmetry.isWithinSupercell(this.ndims, pttemp, this.minXYZ0.x, this.maxXYZ0.x, this.minXYZ0.y, this.maxXYZ0.y, this.minXYZ0.z, this.maxXYZ0.z, this.packingRange)) {
continue;
}}if (checkSymmetryMinMax) JS.UnitCell.setSymmetryMinMax(c, this.rmin, this.rmax);
var special = null;
if (checkDistance) {
if (checkSymmetryRange && (c.x < this.rmin.x || c.y < this.rmin.y || c.z < this.rmin.z || c.x > this.rmax.x || c.y > this.rmax.y || c.z > this.rmax.z)) continue;
var minDist2 = 3.4028235E38;
var j0 = (this.checkAll ? this.asc.ac : pt0);
var name = a.atomName;
var id = (code == null ? a.altLoc : subSystemId);
for (var j = j00; j < j0; j++) {
if (bsAtoms != null && !bsAtoms.get(j)) continue;
var pc = cartesians[j - this.firstAtom];
if (pc == null) continue;
var d2 = c.distanceSquared(pc);
if (checkNearAtoms && d2 < minCartDist2) {
if (checkOps) {
excludedOps.set(iSym);
continue out;
}special = this.asc.atoms[j];
if ((special.atomName == null || special.atomName.equals(name)) && special.altLoc == id) break;
special = null;
}if (checkRange111 && j < baseCount && d2 < minDist2) minDist2 = d2;
}
if (checkRange111 && minDist2 > range2) continue;
}if (checkOps) {
checkDistance = false;
}var atomSite = a.atomSite;
if (special != null) {
if (addBonds) atomMap[atomSite] = special.index;
special.bsSymmetry.set(cpt);
special.bsSymmetry.set(spt);
} else {
if (addBonds) atomMap[atomSite] = this.asc.ac;
var atom1 = a.copyTo(pttemp, this.asc);
if (this.asc.bsAtoms != null) this.asc.bsAtoms.set(atom1.index);
if (timeReversal != 0 && atom1.vib != null) {
(sym.getSpaceGroupOperation(iSym)).rotateSpin(atom1.vib);
}if (atom1.part < 0) {
var key = Integer.$valueOf(iSym * 1000 + 500 + atom1.part);
if (this.disorderMap == null) this.disorderMap =  new java.util.Hashtable();
var ch = this.disorderMap.get(key);
if (ch == null) {
var ia = Integer.$valueOf(atom1.part);
var isNew = (this.disorderMap.get(ia) == null);
if (this.disorderMapMax == 0 || this.disorderMapMax == 122) {
this.disorderMapMax = 64;
} else if (this.disorderMapMax == 90) {
this.disorderMapMax = 96;
}ch =  new Character(isNew ? atom1.altLoc : String.fromCharCode(++this.disorderMapMax));
this.disorderMap.put(key, ch);
if (isNew) this.disorderMap.put(ia, ch);
}atom1.altLoc = ch.charValue();
}atom1.atomSite = atomSite;
if (code != null) atom1.altLoc = subSystemId;
atom1.bsSymmetry = JU.BSUtil.newAndSetBit(cpt);
atom1.bsSymmetry.set(spt);
if (addCartesian) cartesians[pt++] = c;
var tensors = a.tensors;
if (tensors != null) {
atom1.tensors = null;
for (var j = tensors.size(); --j >= 0; ) {
var t = tensors.get(j);
if (t == null) continue;
if (nOp == 1) atom1.addTensor(t.copyTensor(), null, false);
 else this.addRotatedTensor(atom1, t, iSym, false, sym);
}
}}}
if (addBonds) {
var bonds = this.asc.bonds;
var atoms = this.asc.atoms;
var key;
for (var bondNum = this.asc.bondIndex0; bondNum < this.bondCount0; bondNum++) {
var bond = bonds[bondNum];
var atom1 = atoms[bond.atomIndex1];
var atom2 = atoms[bond.atomIndex2];
if (atom1 == null || atom2 == null || atom2.atomSetIndex < atom1.atomSetIndex) continue;
var ia1 = atomMap[atom1.atomSite];
var ia2 = atomMap[atom2.atomSite];
if (ia1 > ia2) {
var i = ia1;
ia1 = ia2;
ia2 = i;
}if ((ia1 != ia2 && (ia1 >= bondAtomMin || ia2 >= bondAtomMin)) && this.bondsFound.indexOf(key = "-" + ia1 + "," + ia2) < 0) {
this.bondsFound.append(key);
this.asc.addNewBondWithOrder(ia1, ia2, bond.order);
}}
}}
return pt;
}, "~N,~N,~N,~N,~N,~N,~A,J.adapter.smarter.MSInterface,JU.BS,~A");
Clazz.defineMethod(c$, "setPartProperty", 
function(){
for (var iset = this.asc.atomSetCount; --iset >= 0; ) {
var parts =  Clazz.newFloatArray (this.asc.getAtomSetAtomCount(iset), 0);
for (var i = 0, ia = this.asc.getAtomSetAtomIndex(iset), n = parts.length; i < n; i++) {
var a = this.asc.atoms[ia++];
parts[i] = a.part;
}
this.asc.setAtomProperties("part", parts, iset, false);
}
});
Clazz.defineMethod(c$, "trimToUnitCell", 
function(iAtomFirst){
var atoms = this.asc.atoms;
var bs = this.updateBSAtoms();
if (this.acr.noPack) {
for (var i = bs.nextSetBit(iAtomFirst); i >= 0; i = bs.nextSetBit(i + 1)) {
if (!J.adapter.smarter.XtalSymmetry.removePacking(this.ndims, atoms[i], this.minXYZ.x, this.maxXYZ.x, this.minXYZ.y, this.maxXYZ.y, this.minXYZ.z, this.maxXYZ.z, this.packingRange)) bs.clear(i);
}
} else {
for (var i = bs.nextSetBit(iAtomFirst); i >= 0; i = bs.nextSetBit(i + 1)) {
if (!J.adapter.smarter.XtalSymmetry.isWithinSupercell(this.ndims, atoms[i], this.minXYZ.x, this.maxXYZ.x, this.minXYZ.y, this.maxXYZ.y, this.minXYZ.z, this.maxXYZ.z, this.packingRange)) bs.clear(i);
}
}}, "~N");
Clazz.defineMethod(c$, "updateBSAtoms", 
function(){
var bs = this.asc.bsAtoms;
if (bs == null) bs = this.asc.bsAtoms = JU.BSUtil.newBitSet2(0, this.asc.ac);
if (bs.nextSetBit(this.firstAtom) < 0) bs.setBits(this.firstAtom, this.asc.ac);
return bs;
});
Clazz.defineMethod(c$, "updateSupercellAtomSites", 
function(matSuper, bsAtoms, slop){
var n = bsAtoms.cardinality();
var baseAtoms =  new Array(n);
var nbase = 0;
var slop2 = slop * slop;
for (var i = bsAtoms.nextSetBit(0); i >= 0; i = bsAtoms.nextSetBit(i + 1)) {
var a = this.asc.atoms[i];
var p =  new J.adapter.smarter.Atom();
p.setT(a);
if (matSuper != null) {
matSuper.rotTrans(p);
JU.SimpleUnitCell.unitizeDimRnd(3, p, slop);
}p.atomSerial = a.atomSite;
p.atomSite = a.atomSite;
this.symmetry.unitize(p);
var found = false;
for (var ib = 0; ib < nbase; ib++) {
var b = baseAtoms[ib];
if (p.atomSerial == b.atomSerial && p.distanceSquared(b) < slop2) {
found = true;
a.atomSite = b.atomSite;
break;
}}
if (!found) {
a.atomSite = p.atomSite = nbase;
baseAtoms[nbase++] = p;
}}
}, "JU.M4,JU.BS,~N");
Clazz.defineMethod(c$, "newFileSymmetry", 
function(){
return  new J.adapter.smarter.XtalSymmetry.FileSymmetry();
});
/*if3*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
this.spinFrameStr = null;
this.spinFrameExt = null;
this.spinFrameRotationMatrix = null;
this.nSpins = 0;
this.spinPointGroupAxesXYZ = null;
this.doNormalizeSpinFrame = false;
Clazz.instantialize(this, arguments);}, J.adapter.smarter.XtalSymmetry, "FileSymmetry", JS.Symmetry);
Clazz.defineMethod(c$, "mapSpins", 
function(map){
this.spaceGroup.mapSpins(map);
}, "java.util.Map");
Clazz.defineMethod(c$, "addMagLatticeVectors", 
function(lattvecs){
return this.spaceGroup.addMagLatticeVectors(lattvecs);
}, "JU.Lst");
Clazz.defineMethod(c$, "addSpinLattice", 
function(lstSpinFrames, mapSpinIdToUVW){
this.spaceGroup.addSpinLattice(lstSpinFrames, mapSpinIdToUVW);
}, "JU.Lst,java.util.Map");
Clazz.defineMethod(c$, "setSpinList", 
function(configuration){
return this.spaceGroup.setSpinList(configuration);
}, "~S");
Clazz.defineMethod(c$, "createSpaceGroup", 
function(desiredSpaceGroupIndex, name, data, modDim){
this.spaceGroup = JS.SpaceGroup.createSpaceGroup(desiredSpaceGroupIndex, name, data, modDim);
if (this.spaceGroup != null && JU.Logger.debugging) JU.Logger.debug("using generated space group " + this.spaceGroup.dumpInfo());
return this.spaceGroup != null;
}, "~N,~S,~O,~N");
Clazz.defineMethod(c$, "fcoord", 
function(p){
return JS.SymmetryOperation.fcoord(p, " ");
}, "JU.T3");
Clazz.defineMethod(c$, "getRotTransArrayAndXYZ", 
function(xyz, rotTransMatrix){
JS.SymmetryOperation.getRotTransArrayAndXYZ(null, xyz, rotTransMatrix, false, true, false, null);
}, "~S,~A");
Clazz.defineMethod(c$, "getSpaceGroupOperationCode", 
function(iOp){
return this.spaceGroup.symmetryOperations[iOp].subsystemCode;
}, "~N");
Clazz.defineMethod(c$, "getTensor", 
function(vwr, parBorU){
if (parBorU == null) return null;
if (this.unitCell == null) this.unitCell = JS.UnitCell.fromParams( Clazz.newFloatArray(-1, [1, 1, 1, 90, 90, 90]), true, 1.0E-4);
return this.unitCell.getTensor(vwr, parBorU);
}, "JV.Viewer,~A");
Clazz.defineMethod(c$, "getSpaceGroupTitle", 
function(){
var s = this.getSpaceGroupName();
return (s.startsWith("cell=") ? s : this.spaceGroup != null ? this.spaceGroup.asString() : this.unitCell != null && this.unitCell.name.length > 0 ? "cell=" + this.unitCell.name : "");
});
Clazz.defineMethod(c$, "setPrecision", 
function(prec){
this.unitCell.setPrecision(prec);
}, "~N");
Clazz.defineMethod(c$, "toFractionalM", 
function(m){
if (!this.$isBio) this.unitCell.toFractionalM(m);
}, "JU.M4");
Clazz.defineMethod(c$, "toUnitCellRnd", 
function(pt, offset){
this.unitCell.toUnitCellRnd(pt, offset);
}, "JU.T3,JU.T3");
Clazz.defineMethod(c$, "twelfthify", 
function(pt){
this.unitCell.twelfthify(pt);
}, "JU.P3");
Clazz.defineMethod(c$, "getConventionalUnitCell", 
function(latticeType, primitiveToCrystal){
return (this.unitCell == null || latticeType == null ? null : this.unitCell.getConventionalUnitCell(latticeType, primitiveToCrystal));
}, "~S,JU.M3");
Clazz.defineMethod(c$, "getSpaceGroupIndex", 
function(){
return this.spaceGroup.getIndex();
});
Clazz.defineMethod(c$, "getUnitCellF2C", 
function(){
return this.unitCell.getF2C();
});
Clazz.defineMethod(c$, "addInversion", 
function(){
var ops = this.spaceGroup.symmetryOperations;
var inv =  new JU.M4();
inv.m00 = inv.m11 = inv.m22 = -1;
inv.m33 = 1;
var n = this.getSpaceGroupOperationCount();
var m =  new JU.M4();
for (var i = 0; i < n; i++) {
m.mul2(inv, ops[i]);
var s = JS.SymmetryOperation.getXYZFromMatrix(m, true, true, false);
this.addSpaceGroupOperation(s, 0);
}
});
Clazz.defineMethod(c$, "rotateAxes", 
function(iop, axes, ptTemp, mTemp){
return (iop == 0 ? axes : this.spaceGroup.symmetryOperations[iop].rotateAxes(axes, this.unitCell, ptTemp, mTemp));
}, "~N,~A,JU.P3,JU.M3");
Clazz.defineMethod(c$, "preSymmetryFinalizeMoments", 
function(acr, asc, ext, htCellTypes){
this.spinFrameExt = ext;
this.spinFrameToCartXYZ = null;
var version = this.getSpinExt(this.spinFrameExt, "version");
if (version == null) this.doNormalizeSpinFrame = acr.checkFilterKey("SPINNORM");
 else this.doNormalizeSpinFrame = (version.compareTo("0.13.0") < 0);
System.out.println("doNormalizeSpinFrame=" + this.doNormalizeSpinFrame);
var a = acr.unitCellParams[0];
var b = acr.unitCellParams[1];
var c = acr.unitCellParams[2];
this.spinFrameStr = null;
if (this.spinFrameExt != null) {
var spinFrame = this.fixSpinFrameInfo(this.spinFrameExt);
this.spinFrameStr = this.evaluateSpinFrameStr(acr, spinFrame, a, b, c);
htCellTypes.put("spin", this.spinFrameStr);
var spinABC = this.preSymmetrySetSpinFrameMatrices(acr, this.spinFrameStr);
a = spinABC[1].length();
b = spinABC[2].length();
c = spinABC[3].length();
this.spinFrameToCartXYZ =  new JU.M3();
for (var i = 4; --i > 0; ) {
this.spinFrameToCartXYZ.setColumnV(i - 1, spinABC[i]);
}
if (this.spinFrameRotationMatrix != null) {
this.spinFrameToCartXYZ.mul2(this.spinFrameRotationMatrix, this.spinFrameToCartXYZ);
}this.spinPointGroupAxesXYZ =  Clazz.newArray(-1, [ new JU.P3(),  new JU.P3(),  new JU.P3()]);
this.spinPointGroupAxesXYZ[0].setP(spinABC[1]).scale(1 / a);
this.spinPointGroupAxesXYZ[1].setP(spinABC[2]).scale(1 / b);
this.spinPointGroupAxesXYZ[2].setP(spinABC[3]).scale(1 / c);
System.out.println("XtalSymmetry spinFramePp=\n " + this.spinFrameStr + "\nrotation=\n " + this.spinFrameRotationMatrix + "\nspinFrameCartXYZ=\n " + this.spinFrameToCartXYZ);
if (this.spinFrameRotationMatrix != null) asc.setCurrentModelInfo("spinFrameRotationMatrix", this.spinFrameRotationMatrix);
asc.setCurrentModelInfo("pointGroupAxes", this.spinPointGroupAxesXYZ);
}var magneticScaling = JU.P3.new3(1 / a, 1 / b, 1 / c);
var i0 = asc.getAtomSetAtomIndex(asc.iSet);
var vt =  new JU.P3();
for (var i = asc.ac; --i >= i0; ) {
var v = asc.atoms[i].vib;
if (v != null) {
v.scaleT(magneticScaling);
if (v.isFractional) {
if (v.magMoment == 0) {
if (this.unitCell == null) {
this.setUnitCellFromParams(acr.unitCellParams, false, acr.cellSlop);
}this.toCartesian(vt.setP(v), true);
v.magMoment = vt.length();
}}}}
}, "J.adapter.smarter.AtomSetCollectionReader,J.adapter.smarter.AtomSetCollection,java.util.Map,java.util.Map");
Clazz.defineMethod(c$, "fixSpinFrameInfo", 
function(spinFrameExt){
var bs =  new JU.BS();
var errMsg = "";
for (var i = 0; i < 4; i++) {
if (spinFrameExt.containsKey(J.adapter.smarter.XtalSymmetry.FileSymmetry.transformTags[i])) bs.set(i);
}
if (bs.cardinality() > 1) {
bs.clearBits(0, bs.length() - 1);
}for (var i = 0; i < 4; i++) {
if (!bs.get(i)) {
var s = spinFrameExt.remove(J.adapter.smarter.XtalSymmetry.FileSymmetry.transformTags[i]);
if (s != null) errMsg += "Redundant spin transform removed: " + J.adapter.smarter.XtalSymmetry.FileSymmetry.transformTags[i] + "\n";
}}
var t = Math.max(0, bs.nextSetBit(0));
var spinFrame = spinFrameExt.get(J.adapter.smarter.XtalSymmetry.FileSymmetry.transformTags[t]);
if (spinFrame == null) {
t = 3;
spinFrame = "a,b,c";
} else if (spinFrameExt.containsKey("ignoreForCollinear")) {
errMsg = "Structure is collinear, but spinFrame is present. Spin frame " + spinFrame + " ignored; using 'a,b,c'\n";
t = 3;
spinFrame = "a,b,c";
} else if (spinFrameExt.containsKey("flagForCollinear") && !spinFrame.equals("a,b,c")) {
errMsg = "Structure is collinear, but spinFrame is present. Collinear nature ignored. Spin frame " + spinFrame + " is being used\n";
}spinFrame = J.adapter.smarter.XtalSymmetry.FileSymmetry.transformTags[t] + ":" + spinFrame;
System.out.println("XtalSymmetry using " + spinFrame);
if (errMsg.length > 0) System.err.print(errMsg);
spinFrame = spinFrame.substring(spinFrame.lastIndexOf("_", spinFrame.indexOf(':')) + 1);
return spinFrame;
}, "java.util.Map");
Clazz.defineMethod(c$, "evaluateSpinFrameStr", 
function(acr, s, a, b, c){
var pt = s.indexOf(":");
var type = s.substring(0, pt);
s = s.substring(pt + 1);
switch (type) {
case "abc":
case "matrix":
if (s.indexOf("mod(") >= 0) {
s = JU.PT.rep(s, "mod(a)", "(" + a + ")");
s = JU.PT.rep(s, "mod(b)", "(" + b + ")");
s = JU.PT.rep(s, "mod(c)", "(" + c + ")");
}var sf = JU.SimpleUnitCell.parseSimpleMath(acr.vwr, s);
if (sf.charAt(0) == '[') {
var m4 = JU.M4.newM4(null);
m4.setRotationScale(JU.Escape.unescapeMatrix(sf));
sf = JS.SymmetryOperation.getTransformABCd(m4, false, true);
}return sf;
case "hex":
case "cartn":
return type.substring(0, 1) + "::" + s.$replace(',', ' ');
}
return null;
}, "J.adapter.smarter.AtomSetCollectionReader,~S,~N,~N,~N");
Clazz.defineMethod(c$, "preSymmetrySetSpinFrameMatrices", 
function(acr, spinFrameStr){
System.out.println("XtalSymmetry.setSpinFrameMatrices using frame " + spinFrameStr);
this.setUnitCellFromParams(acr.unitCellParams, false, acr.cellSlop);
if (spinFrameStr.indexOf("::") == 1) {
var s = spinFrameStr.substring(3);
var ca = JU.PT.parseFloatArray(s.substring(1, s.length - 1));
this.spinFrameRotationMatrix = J.adapter.smarter.XtalSymmetry.FileSymmetry.fromEulerZXZ(ca[0], ca[1], ca[2]);
System.out.println("XS " + this.spinFrameRotationMatrix);
spinFrameStr = null;
}var spinABC = JS.UnitCell.getMatrixAndUnitCell(acr.vwr, this.unitCell, spinFrameStr, null);
if (this.doNormalizeSpinFrame) {
spinABC[1].normalize();
spinABC[2].normalize();
spinABC[3].normalize();
}var strAxis = this.getSpinExt(this.spinFrameExt, "axis");
if (strAxis != null) {
var angle = JU.PT.parseFloat(this.getSpinExt(this.spinFrameExt, "angle"));
if (!Float.isNaN(angle)) {
var axis = this.getAxis(strAxis);
if (axis != null) {
acr.addMoreUnitCellInfo("rotation_axis_xyz=" + axis.x + "," + axis.y + "," + axis.z);
this.spinFrameRotationMatrix =  new JU.M3().setAA(JU.A4.newVA(axis, (angle * (0.017453292519943295))));
}}}return spinABC;
}, "J.adapter.smarter.AtomSetCollectionReader,~S");
c$.fromEulerZXZ = Clazz.defineMethod(c$, "fromEulerZXZ", 
function(alpha, beta, gamma){
alpha *= (0.017453292519943295);
beta *= (0.017453292519943295);
gamma *= (0.017453292519943295);
var ca = Math.cos(alpha);
var sa = Math.sin(alpha);
var cb = Math.cos(beta);
var sb = Math.sin(beta);
var cg = Math.cos(gamma);
var sg = Math.sin(gamma);
var m =  new JU.M3();
m.m00 = ca * cg - cb * sa * sg;
m.m01 = -ca * sg - cb * cg * sa;
m.m02 = sa * sb;
m.m10 = cg * sa + ca * cb * sg;
m.m11 = ca * cb * cg - sa * sg;
m.m12 = -ca * sb;
m.m20 = sb * sg;
m.m21 = cg * sb;
m.m22 = cb;
return m;
}, "~N,~N,~N");
Clazz.defineMethod(c$, "getVariableAxis", 
function(strAxis){
var pt = JU.P3.new3(1, 1, 1);
strAxis.$replace('n', ' ').$replace('u', 'x').$replace('v', 'y').$replace('w', 'z');
var m = this.convertTransform(strAxis, null);
m.rotate(pt);
return  Clazz.newFloatArray(-1, [pt.x, pt.y, pt.z]);
}, "~S");
Clazz.defineMethod(c$, "getAxis", 
function(axis){
var v = null;
var abc = null;
var isCartesian = false;
if (axis.indexOf("n") >= 0) {
abc = this.getVariableAxis(axis);
} else if (axis.startsWith("[")) {
axis = axis.$replace('[', ' ').$replace(']', ' ').$replace(',', ' ').trim();
axis = JU.PT.rep(axis, "  ", " ");
v = JU.PT.split(axis, " ");
isCartesian = true;
} else {
v = axis.$plit(",");
}if (abc == null && v.length == 3) {
abc =  Clazz.newFloatArray (3, 0);
for (var i = 3; --i >= 0; ) {
abc[i] = JU.PT.parseFloat(v[i].trim());
if (Float.isNaN(abc[i])) {
return null;
}}
}if (abc == null) return null;
var a = JU.V3.new3(abc[0], abc[1], abc[2]);
if (!isCartesian) this.unitCell.toCartesian(a, true);
a.normalize();
return a;
}, "~S");
Clazz.defineMethod(c$, "getSpinExt", 
function(spinFrameExt, name){
return (spinFrameExt == null ? null : spinFrameExt.get(name));
}, "java.util.Map,~S");
Clazz.defineMethod(c$, "postSymmetrySetMagneticMoments", 
function(asc, isCartesian){
if (this.nSpins > 0) return this.nSpins;
var i0 = asc.getAtomSetAtomIndex(asc.iSet);
for (var i = asc.ac; --i >= i0; ) {
var v = asc.atoms[i].vib;
if (v != null) {
if (v.modDim > 0) {
(v).setMoment();
this.nSpins++;
} else {
var v1 = v.clone();
if (this.spinFrameToCartXYZ != null) {
this.spinFrameToCartXYZ.rotate(v1);
} else if (!isCartesian) {
this.toCartesian(v1, true);
}if (v1.lengthSquared() > 0) {
if (v1.magMoment != 0) v1.scale(v1.magMoment / v1.length());
this.nSpins++;
} else if (v1.modDim == 0) {
v1 = null;
}asc.atoms[i].vib = v1;
}}}
return this.nSpins;
}, "J.adapter.smarter.AtomSetCollection,~B");
c$.transformTags =  Clazz.newArray(-1, ["spinframe_orientation_hex", "spinframe_orientation_cartn", "transform_spinframe_p_matrix", "transform_spinframe_p_abc"]);
/*eoif3*/})();
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
