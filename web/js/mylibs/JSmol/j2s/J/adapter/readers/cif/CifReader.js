Clazz.declarePackage("J.adapter.readers.cif");
Clazz.load(["J.adapter.smarter.AtomSetCollectionReader", "JU.Lst", "$.P3"], "J.adapter.readers.cif.CifReader", ["java.util.Hashtable", "javajs.api.Interface", "JU.BS", "$.CifDataParser", "$.PT", "$.Rdr", "J.adapter.smarter.Atom", "J.api.JmolAdapter", "JU.Logger", "$.SimpleUnitCell", "$.Vibration"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.ignoreCollinearSpinBasisIfGiven = false;
this.flagCollinearSpinBasisIfGiven = true;
this.isCollinear = false;
this.subParser = null;
this.modr = null;
this.cifParser = null;
this.filterAssembly = false;
this.allowRotations = true;
this.readIdeal = true;
this.configurationPtr = -2147483648;
this.useAuthorChainID = true;
this.newAtomSetLabel = -1;
this.htAudit = null;
this.symops = null;
this.skipping = false;
this.isAFLOW = false;
this.pdbID = null;
this.lstSpinLattices = null;
this.mapSpinIdToUVW = null;
this.thisDataSetName = "";
this.lastDataSetName = null;
this.chemicalName = "";
this.thisStructuralFormula = "";
this.thisFormula = "";
this.iHaveDesiredModel = false;
this.isMMCIF = false;
this.isLigand = false;
this.haveAromatic = false;
this.haveHAtoms = false;
this.molecularType = "GEOM_BOND default";
this.lastAltLoc = '\0';
this.conformationIndex = 0;
this.nMolecular = 0;
this.appendedData = null;
this.nAtoms = 0;
this.ac = 0;
this.auditBlockCode = null;
this.lastSpaceGroupName = null;
this.isCourseGrained = false;
this.noLattice = false;
this.haveCellWaveVector = false;
this.modulated = false;
this.htGroup1 = null;
this.nAtoms0 = 0;
this.titleAtomSet = 1;
this.addAtomLabelNumbers = false;
this.ignoreGeomBonds = false;
this.allowWyckoff = true;
this.stopOn_SHELX_HKL = false;
this.spinFrameSetByFILTER = false;
this.spinFrame = null;
this.spinFrameExt = null;
this.isMagCIF = false;
this.isSpinCIF = false;
this.haveSpinReferences = false;
this.haveMagneticMoments = false;
this.spinOnly = false;
this.maxOps = -1;
this.nops = 0;
this.modelMap = null;
this.haveGlobalDummy = false;
this.structureId = null;
this.htCellTypes = null;
this.htOxStates = null;
this.bondTypes = null;
this.disorderAssembly = ".";
this.lastDisorderAssembly = null;
this.lattvecs = null;
this.magCenterings = null;
this.maxSerial = 0;
this.htJmolNames = null;
this.atomRadius = null;
this.bsConnected = null;
this.bsSets = null;
this.ptOffset = null;
this.bsMolecule = null;
this.bsExclude = null;
this.firstAtom = 0;
this.atoms = null;
this.bsBondDuplicates = null;
this.key = null;
this.key0 = null;
this.field = null;
this.isLoop = false;
this.col2key = null;
this.key2col = null;
this.firstChar = '\0';
Clazz.instantialize(this, arguments);}, J.adapter.readers.cif, "CifReader", J.adapter.smarter.AtomSetCollectionReader);
Clazz.prepareFields (c$, function(){
this.bondTypes =  new JU.Lst();
this.ptOffset =  new JU.P3();
this.col2key =  Clazz.newIntArray (100, 0);
this.key2col =  Clazz.newIntArray (100, 0);
});
Clazz.defineMethod(c$, "resetGlobalsForNewData", 
function(){
this.isAFLOW = false;
this.pdbID = null;
System.out.println("sgName is " + this.sgName);
this.latticeType = null;
this.iHaveUnitCell = this.ignoreFileUnitCell;
this.iHaveSymmetryOperators = false;
this.nops = 0;
this.isSpinCIF = this.isMagCIF = false;
this.haveMagneticMoments = false;
this.isMagCIF = this.isSpinCIF = false;
this.haveSpinReferences = this.haveMagneticMoments = false;
this.lstSpinLattices = null;
this.mapSpinIdToUVW = null;
if (!this.spinFrameSetByFILTER) this.spinFrame = null;
this.spinFrameExt = null;
});
Clazz.overrideMethod(c$, "initializeReader", 
function(){
this.initSubclass();
this.stopOn_SHELX_HKL = this.checkFilterKey("STOPONSHELXHKL");
this.allowPDBFilter = true;
this.appendedData = this.htParams.get("appendedData");
this.spinOnly = this.checkFilterKey("SPINONLY");
this.noLattice = this.checkFilterKey("NOLATTICE");
var conf = this.getFilter("CONF ");
if (conf != null) this.configurationPtr = this.parseIntStr(conf);
if (this.filterCased != null && this.filterCased.toLowerCase().startsWith("spinframe=")) {
this.spinFrame = this.filterCased.substring(10).trim();
var pt = this.spinFrame.indexOf(";");
if (pt >= 0) this.spinFrame = this.spinFrame.substring(0, pt);
this.spinFrameSetByFILTER = true;
}this.isMolecular = this.checkFilterKey("MOLECUL") && !this.checkFilterKey("BIOMOLECULE");
this.ignoreGeomBonds = this.checkFilterKey("IGNOREGEOMBOND") || this.checkFilterKey("IGNOREBOND");
this.isPrimitive = this.checkFilterKey("PRIMITIVE");
this.readIdeal = !this.checkFilterKey("NOIDEAL");
this.allowWyckoff = !this.checkFilterKey("NOWYCKOFF");
this.filterAssembly = this.checkFilterKey("$");
this.useAuthorChainID = !this.checkFilterKey("NOAUTHORCHAINS");
if (this.isMolecular) {
this.forceSymmetry(false);
this.molecularType = "filter \"MOLECULAR\"";
}this.checkNearAtoms = !this.checkFilterKey("NOSPECIAL");
this.allowRotations = !this.checkFilterKey("NOSYM");
if (this.binaryDoc != null) return;
this.readCifData();
this.continuing = false;
});
Clazz.defineMethod(c$, "initSubclass", 
function(){
});
Clazz.defineMethod(c$, "readCifData", 
function(){
this.cifParser = this.getCifDataParser();
this.line = "";
this.cifParser.peekToken();
this.addAtomLabelNumbers = (this.cifParser.getFileHeader().startsWith("# primitive CIF file created by Jmol"));
while (this.continueWith(this.key = this.cifParser.peekToken()) && this.readEntryOrLoopData()) {
}
if (this.appendedData != null) {
this.cifParser = (this.getInterface("JU.CifDataParser")).set(null, JU.Rdr.getBR(this.appendedData), this.debugging);
while ((this.key = this.cifParser.peekToken()) != null) if (!this.readEntryOrLoopData()) break;

}});
Clazz.defineMethod(c$, "continueWith", 
function(key){
var isHKL = false;
var ret = key != null && (!this.stopOn_SHELX_HKL || (this.ac == 0 || !key.equals("_shelx_hkl_file")));
if (ret && isHKL) System.err.println("CIFReader reading _shelx_hkl_file; use FILTER 'StopOnShelxHKL' to stop reading when this is found");
return ret;
}, "~S");
Clazz.defineMethod(c$, "getCifDataParser", 
function(){
return  new JU.CifDataParser().set(this, null, this.debugging);
});
Clazz.defineMethod(c$, "readEntryOrLoopData", 
function(){
if (this.key.startsWith("data_")) {
return this.newData();
}if (this.skipping && this.key.equals("_audit_block_code")) {
this.iHaveDesiredModel = false;
this.skipping = false;
}this.isLoop = this.isLoopKey();
if (this.isLoop) {
if (this.skipping && !this.isMMCIF) {
this.cifParser.getTokenPeeked();
this.skipLoop(false);
} else {
this.processLoopBlock();
}return true;
}if (this.key.indexOf("_") != 0) {
JU.Logger.warn(this.key.startsWith("save_") ? "CIF reader ignoring save_" : "CIF ERROR ? should be an underscore: " + this.key);
this.cifParser.getTokenPeeked();
} else if (!this.getData()) {
return true;
}if (!this.skipping) {
this.key = this.cifParser.fixKey(this.key0 = this.key);
if (this.key.startsWith("_chemical_name") || this.key.equals("_chem_comp_name")) {
this.processChemicalInfo("name");
} else if (this.key.startsWith("_chemical_formula_structural")) {
this.processChemicalInfo("structuralFormula");
} else if (this.key.startsWith("_chemical_formula_sum") || this.key.equals("_chem_comp_formula")) {
this.processChemicalInfo("formula");
} else if (this.key.equals("_cell_modulation_dimension")) {
this.modDim = this.parseIntField();
if (this.modr != null) this.modr.setModDim(this.modDim);
} else if (this.skipKey(this.key)) {
} else if (this.key.startsWith("_cell") && this.key.indexOf("_commen_") < 0) {
this.processCellParameter();
} else if (this.key.startsWith("_atom_sites_fract_tran")) {
this.processUnitCellTransformMatrix();
} else if (this.key.startsWith("_audit")) {
if (this.key.equals("_audit_block_code")) {
this.processsAuditBlock();
} else if (this.key.equals("_audit_creation_date")) {
this.symmetry = null;
}} else if (this.key.startsWith("_chem_comp_atom") || this.key.startsWith("_atom")) {
this.processLoopBlock();
} else if (this.key.startsWith("_symmetry_space_group_name_h-m") || this.key.equals("_space_group_it_number") || this.key.startsWith("_symmetry_space_group_name_hall") || this.key.startsWith("_space_group_name") || this.key.contains("_ssg_name") || this.key.contains("_name_chen") || this.key.contains("_magn_name") || this.key.contains("_magn_number") || this.key.contains("_bns_name")) {
this.processSymmetrySpaceGroupName();
} else if (this.key.startsWith("_space_group_transform") || this.key.startsWith("_parent_space_group") || this.key.startsWith("_space_group_magn_transform") || this.key.contains("_space_group_spin")) {
this.processUnitCellTransform();
} else if (this.key.contains("_database_code")) {
this.addModelTitle("ID");
} else if ("__citation_title__publ_section_title__active_magnetic_irreps_details__".contains("_" + this.key + "__")) {
this.addModelTitle("TITLE");
} else if (this.key.startsWith("_aflow_")) {
this.isAFLOW = true;
} else if (this.key.equals("_symmetry_int_tables_number")) {
var intTableNo = this.parseIntStr(this.field);
this.rotateHexCell = (this.isAFLOW && (intTableNo >= 143 && intTableNo <= 194));
} else if (this.key.equals("_entry_id")) {
this.pdbID = this.field;
} else if (this.key.startsWith("_topol_")) {
this.getTopologyParser().ProcessRecord(this.key, this.field);
} else if (this.key.equals("_structure_id")) {
this.structureId = this.field;
} else {
this.processSubclassEntry();
}}return true;
});
Clazz.defineMethod(c$, "processsAuditBlock", 
function(){
this.auditBlockCode = J.adapter.readers.cif.CifReader.fullTrim(this.field).toUpperCase();
this.appendLoadNote(this.auditBlockCode);
if (this.htAudit != null) {
if (this.auditBlockCode.contains("_MOD_")) {
var key = JU.PT.rep(this.auditBlockCode, "_MOD_", "_REFRNCE_");
if (this.asc.setSymmetryFromAuditBlock(this.htAudit.get(key)) != null) {
this.unitCellParams = this.asc.getSymmetry().getUnitCellParams();
this.iHaveUnitCell = true;
}}if (this.symops != null) for (var i = 0; i < this.symops.size(); i++) this.setSymmetryOperator(this.symops.get(i));

}if (this.lastSpaceGroupName != null) this.setSpaceGroupName(this.lastSpaceGroupName);
});
Clazz.defineMethod(c$, "setSymmetryOperator", 
function(xyz){
if (this.maxOps >= 0 && this.nops++ > this.maxOps) return 0;
return Clazz.superCall(this, J.adapter.readers.cif.CifReader, "setSymmetryOperator", [xyz]);
}, "~S");
Clazz.defineMethod(c$, "newData", 
function(){
this.isLigand = false;
if (this.asc.atomSetCount == 0) this.iHaveDesiredModel = false;
if (this.iHaveDesiredModel) return false;
if (this.desiredModelNumber != -2147483648) this.appendLoadNote(null);
this.newModel(-1);
this.haveCellWaveVector = false;
if (this.auditBlockCode == null) this.modulated = false;
if (!this.skipping) {
this.nAtoms0 = this.asc.ac;
this.processDataParameter();
this.nAtoms = this.asc.ac;
}return true;
});
Clazz.defineMethod(c$, "skipKey", 
function(key){
return key.startsWith("_shelx_") || key.startsWith("_reflns_") || key.startsWith("_diffrn_");
}, "~S");
Clazz.defineMethod(c$, "addModelTitle", 
function(key){
if (this.asc.atomSetCount > this.titleAtomSet) this.appendLoadNote("\nMODEL: " + (this.titleAtomSet = this.asc.atomSetCount));
this.appendLoadNote(key + ": " + J.adapter.readers.cif.CifReader.fullTrim(this.field));
}, "~S");
Clazz.defineMethod(c$, "processSubclassEntry", 
function(){
if (this.modDim > 0) this.getModulationReader().processEntry();
});
Clazz.defineMethod(c$, "processUnitCellTransform", 
function(){
this.field = JU.PT.replaceAllCharacters(this.field, " ", "");
if (this.key.startsWith("_space_group_spin_")) {
this.processSpinSpaceGroup();
} else if (this.key.contains("_from_parent") || this.key.contains("child_transform")) {
this.addCellType("parent", this.field, true);
} else if (this.key.contains("_to_standard") || this.key.contains("transform_bns_pp_abc")) {
this.addCellType("standard", this.field, false);
}this.appendLoadNote(this.key + ": " + this.field);
});
Clazz.defineMethod(c$, "processSpinSpaceGroup", 
function(){
var tag = this.key.substring(18);
if (tag.indexOf("fsg_") >= 0) tag = JU.PT.rep(tag, "fsg_", "");
switch (tag) {
case "collinear_direction":
case "collinear_direction_xyz":
this.isCollinear = true;
break;
case "transform_spinframe_p_abc":
if (this.spinFrameSetByFILTER) {
System.out.println("CifReader spinFrame set by user to " + this.spinFrame + " file setting ignored: " + this.field);
this.field = this.spinFrame;
}case "transform_spinframe_p_matrix":
case "spinframe_orientation_cartn":
case "spinframe_orientation_hex":
this.addSpinFrameExt(tag, false);
return;
case "rotation_axis_cartn":
this.field = this.addSpinFrameExt("axis", false);
return;
case "rotation_axis_xyz":
this.field = this.addSpinFrameExt("axis", true);
return;
case "rotation_angle":
this.field = this.addSpinFrameExt("angle", false);
return;
case "coplanar_perp_uvw":
break;
case "number_chen":
case "number_chen_liu":
tag = "spsg_number";
break;
case "oriented_spin_space_group_name_linear":
case "name_chen":
case "name_chen_liu":
this.processSymmetrySpaceGroupName();
return;
case "g0_number":
tag = "spsg_G0";
break;
case "l0_number":
tag = "spsg_L0";
break;
case "it":
tag = "spsg_it";
break;
case "ik":
tag = "spsg_ik";
break;
case "spin_part_point_group":
case "spin_space_point_group_name":
tag = "spsg_SPG";
break;
case "transform_to_input_pp":
this.addCellType("input", "!" + this.field, true);
tag = null;
break;
case "transform_to_magnetic_primitive_pp":
this.addCellType("magneticprimitive", "!" + this.field, true);
tag = null;
break;
case "transform_to_l0std_pp":
this.addCellType("l0", "!" + this.field, true);
tag = null;
break;
case "transform_to_g0std_pp":
this.addCellType("g0", "!" + this.field, true);
tag = null;
break;
default:
System.err.println("CIFReader unrecognized spin key " + this.key + " " + this.field);
return;
}
if (tag != null) this.addMoreUnitCellInfo(tag + "=" + this.field);
});
Clazz.defineMethod(c$, "addSpinFrameExt", 
function(name, doClean){
var val = this.field.toString();
if (doClean) val = JU.PT.replaceAllCharacters(val, "[]\"", "");
if (this.spinFrameExt == null) {
this.spinFrameExt =  new java.util.Hashtable();
}this.spinFrameExt.put(name, val);
return val;
}, "~S,~B");
Clazz.defineMethod(c$, "addCellType", 
function(type, data, isFrom){
if (this.htCellTypes == null) this.htCellTypes =  new java.util.Hashtable();
if (data.startsWith("!")) {
data = data.substring(1);
isFrom = !isFrom;
}var cell = (isFrom ? "!" : "") + data;
this.htCellTypes.put(type, cell);
if (type.equalsIgnoreCase(this.strSupercell)) {
this.strSupercell = cell;
this.htCellTypes.put("super", (isFrom ? "!" : "") + data);
this.htCellTypes.put("conventional", (isFrom ? "" : "!") + data);
}}, "~S,~S,~B");
Clazz.defineMethod(c$, "getModulationReader", 
function(){
return (this.modr == null ? this.initializeMSCIF() : this.modr);
});
Clazz.defineMethod(c$, "initializeMSCIF", 
function(){
if (this.modr == null) this.ms = this.modr = this.getInterface("J.adapter.readers.cif.MSCifParser");
this.modulated = (this.modr.initialize(this, this.modDim) > 0);
return this.modr;
});
Clazz.defineMethod(c$, "newModel", 
function(modelNo){
if (modelNo < 0) {
if (this.modelNumber == 1 && this.asc.ac == 0 && this.nAtoms == 0 && !this.haveGlobalDummy && !this.skipping) {
this.modelNumber = 0;
this.haveModel = false;
this.haveGlobalDummy = true;
this.asc.removeCurrentAtomSet();
}modelNo = ++this.modelNumber;
}this.skipping = !this.doGetModel(this.modelNumber = modelNo, null);
if (this.skipping) {
if (!this.isMMCIF) this.cifParser.getTokenPeeked();
return;
}this.chemicalName = "";
this.thisStructuralFormula = "";
this.thisFormula = "";
this.iHaveDesiredModel = this.isLastModel(this.modelNumber);
if (this.isCourseGrained) this.asc.setCurrentModelInfo("courseGrained", Boolean.TRUE);
if (this.nAtoms0 > 0 && this.nAtoms0 == this.asc.ac) {
this.modelNumber--;
this.haveModel = false;
this.asc.removeCurrentAtomSet();
} else if (this.asc.iSet >= 0) {
this.applySymmetryAndSetTrajectory();
}this.structureId = null;
this.isMolecular = false;
if (this.auditBlockCode == null) {
this.modDim = 0;
}}, "~N");
Clazz.overrideMethod(c$, "finalizeSubclassReader", 
function(){
if (this.htOxStates != null) this.setOxidationStates();
if (this.htJmolNames != null) this.setJmolNames();
if (this.asc.iSet > 0 && this.asc.getAtomSetAtomCount(this.asc.iSet) == 0) this.asc.atomSetCount--;
 else if (!this.finalizeSubclass()) this.applySymmetryAndSetTrajectory();
var n = this.asc.atomSetCount;
if (n > 1) this.asc.setCollectionName("<collection of " + n + " models>");
if (this.pdbID != null) this.asc.setCurrentModelInfo("pdbID", this.pdbID);
this.finalizeReaderASCR();
this.addHeader();
if (this.haveAromatic) this.addJmolScript("calculate aromatic");
if (!this.isMMCIF && this.asc.xtalSymmetry != null) {
this.asc.xtalSymmetry.setPartProperty();
}});
Clazz.defineMethod(c$, "setOxidationStates", 
function(){
for (var i = this.asc.ac; --i >= 0; ) {
var a = this.asc.atoms[i];
var sym = a.typeSymbol;
var data;
if (sym != null && (data = this.htOxStates.get(sym)) != null) {
var charge = data[0];
var radius = data[1];
if (!Float.isNaN(charge)) {
a.formalCharge = Math.round(charge);
}if (!Float.isNaN(radius)) {
a.bondingRadius = radius;
}}}
});
Clazz.defineMethod(c$, "addHeader", 
function(){
var header = this.cifParser.getFileHeader();
if (header.length > 0) {
var s = this.setLoadNote();
this.appendLoadNote(null);
this.appendLoadNote(header);
this.appendLoadNote(s);
this.setLoadNote();
this.asc.setInfo("fileHeader", header);
}});
Clazz.defineMethod(c$, "finalizeSubclass", 
function(){
return (this.subParser == null ? false : this.subParser.finalizeReader());
});
Clazz.overrideMethod(c$, "doPreSymmetry", 
function(doApplySymmetry){
if (this.mapSpinIdToUVW != null) {
this.asc.getSymmetry().mapSpins(this.mapSpinIdToUVW);
} else if (this.haveSpinReferences) {
this.lstSpinLattices = null;
this.appendLoadNote("Warning: CIF FILE contains _space_group_symop_spin_lattice_R.uvw_id but not _space_group_symop_spin_operation_U.uvw!");
}if (this.magCenterings != null || this.lstSpinLattices != null) this.addLatticeVectors();
if (this.modDim > 0) this.getModulationReader().setModulation(false, null);
if (this.isMagCIF || this.isSpinCIF) {
if (!this.haveMagneticMoments) {
this.isMagCIF = this.isSpinCIF = false;
} else {
if (this.isSpinCIF) {
var header = this.cifParser.getFileHeader();
var pt = header.indexOf("version -");
if (pt >= 0) {
this.field = header.substring(pt + 9).trim();
this.addSpinFrameExt("version", false);
}if (this.ignoreCollinearSpinBasisIfGiven && this.isCollinear) {
this.field = "true";
this.addSpinFrameExt("ignoreForCollinear", false);
} else if (this.flagCollinearSpinBasisIfGiven && this.isCollinear) {
this.field = "true";
this.addSpinFrameExt("flagForCollinear", false);
}}if (this.htCellTypes == null) this.htCellTypes =  new java.util.Hashtable();
this.asc.getXSymmetry().finalizeMoments(this.spinFrameExt, this.htCellTypes);
this.vibsFractional = true;
}}if (this.isSpinCIF) {
var lst = this.asc.getSymmetry().setSpinList(null);
if (lst != null) {
this.asc.setCurrentModelInfo("spinList", lst);
this.appendLoadNote(lst.size() + " spin operations -- see _M.spinList" + (doApplySymmetry ? " and atom.spin" : ""));
}}}, "~B");
Clazz.overrideMethod(c$, "applySymmetryAndSetTrajectory", 
function(){
var doCheckBonding = this.doCheckUnitCell && !this.isMMCIF;
if (this.isMMCIF) {
this.checkNearAtoms = false;
if (this.asc.iSet >= 0) {
var modelIndex = this.asc.iSet;
this.asc.setCurrentModelInfo("PDB_CONECT_firstAtom_count_max",  Clazz.newIntArray(-1, [this.asc.getAtomSetAtomIndex(modelIndex), this.asc.getAtomSetAtomCount(modelIndex), this.maxSerial]));
}}if (!this.haveCellWaveVector) this.modDim = 0;
if (this.doApplySymmetry && !this.iHaveFractionalCoordinates) this.fractionalizeCoordinates(true);
if (!this.haveCellWaveVector) {
this.modDim = 0;
}if (this.spinOnly) {
var bs = this.asc.getBSAtoms(-1);
for (var i = 0; i < this.asc.ac; i++) {
var isVib = (this.asc.atoms[i].vib != null && this.asc.atoms[i].vib.lengthSquared() > 0);
if (!isVib) bs.clear(i);
}
}this.applySymTrajASCR();
if (this.htCellTypes != null) {
for (var e, $e = this.htCellTypes.entrySet().iterator (); $e.hasNext()&& ((e = $e.next ()) || true);) {
this.asc.setCurrentModelInfo("unitcell_" + e.getKey(), e.getValue());
this.appendLoadNote("unitcell_" + e.getKey() + " = " + e.getValue());
}
this.htCellTypes = null;
}if (!this.haveCellWaveVector) {
if (!this.isMolecular) {
this.asc.setBSAtomsForSet(-1);
}}if (doCheckBonding && (this.bondTypes.size() > 0 || this.isMolecular)) this.setBondingAndMolecules();
this.asc.setCurrentModelInfo("fileHasUnitCell", Boolean.TRUE);
this.asc.xtalSymmetry = null;
});
Clazz.overrideMethod(c$, "finalizeSubclassSymmetry", 
function(haveSymmetry){
var sym = (haveSymmetry ? this.asc.getXSymmetry().getBaseSymmetry() : null);
if (sym != null && sym.getSpaceGroup() == null) {
if (!this.isBinary && !this.isMMCIF) this.appendLoadNote("Invalid or missing space group operations!");
sym = null;
}if (this.modDim > 0 && sym != null) {
this.addLatticeVectors();
this.asc.setTensors();
this.getModulationReader().setModulation(true, sym);
this.modr.finalizeModulation();
}if (sym != null && this.auditBlockCode != null && this.auditBlockCode.contains("REFRNCE")) {
if (this.htAudit == null) this.htAudit =  new java.util.Hashtable();
this.htAudit.put(this.auditBlockCode, sym);
}if (this.subParser != null) this.subParser.finalizeSymmetry(haveSymmetry);
if (sym != null && (this.isMagCIF || this.isSpinCIF)) this.finalizeMagneticMoments();
}, "~B");
Clazz.defineMethod(c$, "finalizeMagneticMoments", 
function(){
if (this.asc.xtalSymmetry == null) return;
this.asc.setNoAutoBond();
this.addJmolScript("vectors on;vectors 0.15;");
var n = this.asc.xtalSymmetry.setMagneticMoments(false);
this.appendLoadNote(n + " magnetic moments - use VECTORS ON/OFF or VECTOR MAX x.x or SELECT VXYZ>0");
});
Clazz.defineMethod(c$, "processDataParameter", 
function(){
this.bondTypes.clear();
this.cifParser.getTokenPeeked();
this.thisDataSetName = (this.key.length < 6 ? "" : this.key.substring(5));
if (this.thisDataSetName.length > 0) this.nextAtomSet();
if (this.debugging) JU.Logger.debug(this.key);
this.resetGlobalsForNewData();
});
Clazz.defineMethod(c$, "nextAtomSet", 
function(){
this.newAtomSetLabel = -1;
this.asc.setCurrentModelInfo("isCIF", Boolean.TRUE);
if (this.asc.iSet >= 0) {
if (this.isMMCIF) {
this.setModelPDB(true);
if (this.pdbID != null) this.asc.setCurrentModelInfo("pdbID", this.pdbID);
}this.newAtomSet();
if (this.isMMCIF) {
this.setModelPDB(true);
if (this.pdbID != null) this.asc.setCurrentModelInfo("pdbID", this.pdbID);
}} else {
this.asc.setCollectionName(this.thisDataSetName);
}});
Clazz.defineMethod(c$, "newAtomSet", 
function(){
this.asc.newAtomSet();
});
Clazz.defineMethod(c$, "processChemicalInfo", 
function(type){
var field = this.field;
if (type.equals("name")) {
this.chemicalName = field = J.adapter.readers.cif.CifReader.fullTrim(field);
this.appendLoadNote(this.chemicalName);
if (!field.equals("?")) this.asc.setInfo("modelLoadNote", field);
} else if (type.equals("structuralFormula")) {
this.thisStructuralFormula = field = J.adapter.readers.cif.CifReader.fullTrim(field);
} else if (type.equals("formula")) {
this.thisFormula = field = J.adapter.readers.cif.CifReader.fullTrim(field);
if (this.thisFormula.length > 1) this.appendLoadNote(this.thisFormula);
}if (this.debugging) {
JU.Logger.debug(type + " = " + field);
}return field;
}, "~S");
Clazz.defineMethod(c$, "processSymmetrySpaceGroupName", 
function(){
if (this.key.indexOf("_ssg_name") >= 0) {
this.modulated = true;
this.latticeType = (this.field).substring(0, 1);
} else if (this.modulated) {
return;
}var s = this.cifParser.toUnicode(this.field);
if (this.key.indexOf("number_bns") >= 0) {
s = (this.lastSpaceGroupName == null ? "BNS #" + s : this.lastSpaceGroupName + " #" + s);
} else {
s = (this.key.indexOf("h-m") > 0 ? "HM:" : this.modulated ? "SSG:" : this.key.indexOf("spin") > 0 || this.key.indexOf("spsg") > 0 ? "spinSG:" : this.key.indexOf("bns") >= 0 ? "BNS:" : this.key.indexOf("hall") >= 0 ? "Hall:" : "") + s;
}this.setSpaceGroupName(this.lastSpaceGroupName = s);
});
Clazz.defineMethod(c$, "addLatticeVectors", 
function(){
if (this.lstSpinLattices != null && !this.noLattice) {
this.asc.getSymmetry().addSpinLattice(this.lstSpinLattices, this.mapSpinIdToUVW);
}if (this.noLattice) return;
this.lattvecs = null;
if (this.magCenterings != null) {
this.latticeType = "Magnetic";
this.lattvecs =  new JU.Lst();
for (var i = 0; i < this.magCenterings.size(); i++) {
var s = this.magCenterings.get(i);
var f =  Clazz.newFloatArray (this.modDim + 4, 0);
if (s.indexOf("x1") >= 0) for (var j = 1; j <= this.modDim + 3; j++) s = JU.PT.rep(s, "x" + j, "");

var tokens = JU.PT.split(JU.PT.replaceAllCharacters(s, "xyz+", ""), ",");
var n = 0;
for (var j = 0; j < tokens.length; j++) {
s = tokens[j].trim();
if (s.length == 0) continue;
if ((f[j] = JU.PT.parseFloatFraction(s)) != 0) n++;
}
if (n >= 2) this.lattvecs.addLast(f);
}
this.magCenterings = null;
} else if (this.latticeType != null && "ABCFI".indexOf(this.latticeType) >= 0) {
this.lattvecs =  new JU.Lst();
try {
this.ms.addLatticeVector(this.lattvecs, this.latticeType);
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
} else {
throw e;
}
}
}if (this.lattvecs != null && this.lattvecs.size() > 0 && this.asc.getSymmetry().addMagLatticeVectors(this.lattvecs)) {
this.appendLoadNote("Note! " + this.lattvecs.size() + " symmetry operators added for lattice centering " + this.latticeType);
for (var i = 0; i < this.lattvecs.size(); i++) this.appendLoadNote(JU.PT.toJSON(null, this.lattvecs.get(i)));

}this.latticeType = null;
});
Clazz.defineMethod(c$, "processCellParameter", 
function(){
for (var i = 6; --i >= 0; ) {
if (this.key.equals(J.api.JmolAdapter.cellParamNames[i])) {
var p = this.parseFloatField();
if (this.rotateHexCell && i == 5 && p == 120) p = -1;
this.setUnitCellItem(i, p);
return;
}}
});
Clazz.defineMethod(c$, "processUnitCellTransformMatrix", 
function(){
var v = this.parseFloatField();
if (Float.isNaN(v)) return;
for (var i = 0; i < J.adapter.readers.cif.CifReader.TransformFields.length; i++) {
if (this.key.indexOf(J.adapter.readers.cif.CifReader.TransformFields[i]) >= 0) {
this.setUnitCellItem(6 + i, v);
return;
}}
});
Clazz.defineMethod(c$, "processLoopBlock", 
function(){
if (this.isLoop) {
this.skipLoopKeyword();
this.key = this.cifParser.peekToken();
if (this.key == null) return;
this.key = this.cifParser.fixKey(this.key0 = this.key);
}if (this.modDim > 0) {
switch (this.getModulationReader().processLoopBlock()) {
case 0:
break;
case -1:
this.skipLoop(false);
case 1:
return;
}
}var isLigand = false;
if (this.key.startsWith("_atom_site") || (isLigand = this.key.startsWith("_chem_comp_atom_"))) {
if (this.processAtomSiteLoopBlock(isLigand)) this.setModelInfoForAtoms();
return;
}if (this.key.startsWith("_space_group_symop") || this.key.startsWith("_symmetry_equiv_pos") || this.key.startsWith("_symmetry_ssg_equiv")) {
if (this.ignoreFileSymmetryOperators || this.modDim > 0 && this.key.indexOf("ssg") < 0) {
JU.Logger.warn("ignoring file-based symmetry operators");
this.skipLoop(false);
} else {
this.processSymmetryOperationsLoopBlock();
}return;
}if (this.key.startsWith("_citation")) {
this.processCitationListBlock();
return;
}if (this.key.startsWith("_atom_type")) {
this.processAtomTypeLoopBlock();
return;
}if (this.key.startsWith("_geom_bond")) {
this.processGeomBondLoopBlock();
return;
}if (this.key.startsWith("_jmol")) {
this.processJmolBlock();
return;
}if (this.processSubclassLoopBlock()) return;
if (this.key.equals("_propagation_vector_seq_id")) {
this.addMore();
return;
}this.skipLoop(false);
});
Clazz.defineMethod(c$, "setModelInfoForAtoms", 
function(){
if (this.structureId != null) {
this.asc.setAtomSetName(this.structureId);
this.thisDataSetName = this.structureId;
this.asc.setCurrentModelInfo("structureID", this.structureId);
}if (this.thisDataSetName.equals("global")) this.asc.setCollectionName(this.thisDataSetName = this.chemicalName);
if (!this.thisDataSetName.equals(this.lastDataSetName)) {
this.asc.setAtomSetName(this.thisDataSetName);
this.lastDataSetName = this.thisDataSetName;
}this.asc.setCurrentModelInfo("chemicalName", this.chemicalName);
this.asc.setCurrentModelInfo("structuralFormula", this.thisStructuralFormula);
this.asc.setCurrentModelInfo("formula", this.thisFormula);
});
Clazz.defineMethod(c$, "processSubclassLoopBlock", 
function(){
if (this.key.startsWith("_topol_")) {
return this.getTopologyParser().processBlock(this.key);
}return false;
});
Clazz.defineMethod(c$, "getTopologyParser", 
function(){
if (this.subParser == null) {
this.subParser = (javajs.api.Interface.getInterface("J.adapter.readers.cif.TopoCifParser"));
this.subParser = this.subParser.setReader(this);
}return this.subParser;
});
Clazz.defineMethod(c$, "addMore", 
function(){
var str;
var n = 0;
try {
while ((str = this.cifParser.peekToken()) != null && str.charAt(0) == '_') {
this.cifParser.getTokenPeeked();
n++;
}
var m = 0;
var s = "";
while ((str = this.cifParser.getNextDataToken()) != null) {
s += str + (m % n == 0 ? "=" : " ");
if (++m % n == 0) {
this.addMoreUnitCellInfo(s.trim());
s = "";
}}
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
} else {
throw e;
}
}
});
Clazz.defineMethod(c$, "disableField", 
function(fieldIndex){
var i = this.key2col[fieldIndex];
if (i != -1) this.col2key[i] = -1;
}, "~N");
Clazz.defineMethod(c$, "processAtomTypeLoopBlock", 
function(){
this.parseLoopParameters(J.adapter.readers.cif.CifReader.atomTypeFields);
while (this.cifParser.getData()) {
var sym = this.getFieldString(0);
if (sym == null) continue;
var oxno = this.parseFloatStr(this.getFieldString(1));
var radius = this.parseFloatStr(this.getFieldString(2));
if (Float.isNaN(oxno) && Float.isNaN(radius)) continue;
if (this.htOxStates == null) this.htOxStates =  new java.util.Hashtable();
this.htOxStates.put(sym,  Clazz.newFloatArray(-1, [oxno, radius]));
}
return true;
});
Clazz.defineMethod(c$, "processJmolBlock", 
function(){
this.htJmolNames =  new java.util.Hashtable();
this.parseLoopParameters(J.adapter.readers.cif.CifReader.jmolAtomFields);
while (this.cifParser.getData()) {
var jmolName = this.getFieldString(1);
var cifName = this.getFieldString(2);
if (jmolName == null || cifName == null) continue;
this.htJmolNames.put(cifName, jmolName);
}
});
Clazz.defineMethod(c$, "setJmolNames", 
function(){
for (var i = 0, n = this.asc.ac; i < n; i++) {
var a = this.asc.atoms[i];
var name = this.htJmolNames.get(a.atomName);
if (name != null) a.atomName = name;
}
});
Clazz.defineMethod(c$, "parseLoopParametersFor", 
function(key, fieldNames){
if (fieldNames[0].charAt(0) == '*') for (var i = fieldNames.length; --i >= 0; ) if (fieldNames[i].charAt(0) == '*') fieldNames[i] = key + fieldNames[i].substring(1);

this.parseLoopParameters(fieldNames);
}, "~S,~A");
Clazz.defineMethod(c$, "fieldProperty", 
function(col){
var k = (col < 0 ? -1 : this.col2key[col]);
if (k == -1) return -1;
this.field = this.cifParser.getColumnData(col);
return (col >= 0 && this.isFieldValid() ? this.col2key[col] : -1);
}, "~N");
Clazz.defineMethod(c$, "processAtomSiteLoopBlock", 
function(isLigand){
this.isLigand = isLigand;
var pdbModelNo = -1;
var haveCoord = true;
var noPreviousReferences = this.asc.atomSymbolicMap.isEmpty();
this.parseLoopParametersFor("_atom_site", J.adapter.readers.cif.CifReader.atomFields);
if (this.key2col[55] != -1) {
this.setFractionalCoordinates(false);
} else if (this.key2col[6] != -1 || this.key2col[52] != -1) {
this.setFractionalCoordinates(false);
this.disableField(3);
this.disableField(4);
this.disableField(5);
if (this.key2col[16] != -1 && !this.isMMCIF) {
this.setIsPDB();
this.isMMCIF = true;
}} else if (this.key2col[3] != -1) {
this.setFractionalCoordinates(true);
this.disableField(6);
this.disableField(7);
this.disableField(8);
} else if (this.key2col[20] != -1 || this.key2col[21] != -1 || this.key2col[63] != -1 || this.key2col[79] != -1) {
haveCoord = false;
} else {
this.skipLoop(false);
return false;
}if (this.key2col[76] != -1 || this.key2col[80] != -1) {
this.disableField(67);
this.disableField(68);
this.disableField(69);
}var modelField = this.key2col[17];
var siteMult = 0;
var atomLabels = (this.isMMCIF ? null : "");
while (this.cifParser.getData()) {
if (modelField >= 0) {
pdbModelNo = this.checkPDBModelField(modelField, pdbModelNo);
if (pdbModelNo < 0) break;
if (this.skipping) continue;
}var atom = null;
var atomName = null;
if (this.isMMCIF) {
if (haveCoord) {
atom =  new J.adapter.smarter.Atom();
} else {
if (this.fieldProperty(this.key2col[20]) != -1 || this.fieldProperty(this.key2col[21]) != -1 || this.fieldProperty(this.key2col[63]) != -1) {
if ((atom = this.asc.getAtomFromName(this.field)) == null) continue;
} else {
continue;
}}} else {
var fNewAtomSet = -1;
var f0 = -1;
var label = -1;
if ((f0 = fNewAtomSet = this.fieldProperty(this.key2col[1])) != -1 && (label = 1) != -1 || (fNewAtomSet = this.fieldProperty(this.key2col[49])) != -1 && (label = 49) != -1 || (fNewAtomSet = this.fieldProperty(this.key2col[73])) != -1 && (label = 73) != -1 || (f0 = fNewAtomSet = this.fieldProperty(this.key2col[20])) != -1 && (label = 20) != -1 || (fNewAtomSet = this.fieldProperty(this.key2col[21])) != -1 && (label = 21) != -1 || (f0 = fNewAtomSet = this.fieldProperty(this.key2col[63])) != -1 && (label = 63) != -1 || (f0 = fNewAtomSet = this.fieldProperty(this.key2col[79])) != -1 && (label = 79) != -1) {
if (f0 != -1 && atomLabels != null) {
atom = this.asc.getAtomFromName(this.field);
if (this.addAtomLabelNumbers || atom != null) {
var key = ";" + this.field + ";";
if (noPreviousReferences) {
atomLabels += key;
}if (atomLabels.indexOf(key) < 0) {
atomLabels += key;
} else {
this.field = atomName = (this.field) + (this.asc.ac + 1);
System.err.println("CifReader found duplicate atom_site_label! New label is " + this.field);
atom = null;
}}}}var field = this.field;
if (atom == null) {
atom =  new J.adapter.smarter.Atom();
if (fNewAtomSet != -1) {
if (this.asc.iSet < 0 && this.newAtomSetLabel == -1) {
this.nextAtomSet();
this.newAtomSet();
this.newAtomSetLabel = label;
}this.asc.atomSymbolicMap.put(field, atom);
}}}var componentId = null;
var id = null;
var authAtom = null;
var authComp = null;
var authSeq = -2147483648;
var authAsym = null;
var wyckoff = null;
var haveAuth = false;
var seqIdOrWyckoffCode = 0;
var n = this.cifParser.getColumnCount();
for (var i = 0; i < n; ++i) {
var tok = this.fieldProperty(i);
var field = this.field;
switch (tok) {
case -1:
break;
case 70:
id = field;
break;
case 50:
case 0:
var elementSymbol;
if (field.length < 2) {
elementSymbol = field;
} else {
var ch1 = Character.toLowerCase(field.charAt(1));
if (J.adapter.smarter.Atom.isValidSym2(this.firstChar, ch1)) {
elementSymbol = "" + this.firstChar + ch1;
} else {
elementSymbol = "" + this.firstChar;
if (!this.haveHAtoms && this.firstChar == 'H') this.haveHAtoms = true;
}}atom.elementSymbol = elementSymbol;
atom.typeSymbol = field;
break;
case 49:
case 1:
case 73:
atom.atomName = (atomName == null ? field : atomName);
break;
case 2:
haveAuth = true;
authAtom = field;
break;
case 48:
case 72:
atom.group3 = field;
break;
case 11:
authComp = field;
haveAuth = true;
break;
case 59:
componentId = field;
break;
case 12:
authAsym = field;
haveAuth = true;
break;
case 71:
atom.sequenceNumber = seqIdOrWyckoffCode = this.parseIntField();
break;
case 74:
if (this.allowWyckoff) {
wyckoff = field;
}break;
case 13:
haveAuth = true;
authSeq = this.parseIntField();
break;
case 55:
var x = this.parseFloatField();
if (this.readIdeal && !Float.isNaN(x)) atom.x = x;
break;
case 56:
var y = this.parseFloatField();
if (this.readIdeal && !Float.isNaN(y)) atom.y = y;
break;
case 57:
var z = this.parseFloatField();
if (this.readIdeal && !Float.isNaN(z)) atom.z = z;
break;
case 3:
atom.x = this.parsePrecision(field);
break;
case 52:
case 6:
atom.x = this.parseCartesianField();
break;
case 4:
atom.y = this.parsePrecision(field);
break;
case 53:
case 7:
atom.y = this.parseCartesianField();
break;
case 5:
atom.z = this.parsePrecision(field);
break;
case 54:
case 8:
atom.z = this.parseCartesianField();
break;
case 51:
atom.formalCharge = this.parseIntStr(field);
break;
case 9:
var floatOccupancy = this.parseFloatField();
if (!Float.isNaN(floatOccupancy)) atom.foccupancy = floatOccupancy;
break;
case 10:
atom.bfactor = this.parseFloatField() * (this.isMMCIF ? 1 : 100);
break;
case 14:
atom.insertionCode = this.firstChar;
break;
case 15:
case 60:
atom.altLoc = this.firstChar;
break;
case 58:
this.disorderAssembly = field;
break;
case 19:
if (!this.isMMCIF) {
atom.part = JU.PT.parseInt(field);
}atom.altLoc = (atom.part < 0 ? field.charAt(1) : this.firstChar);
break;
case 16:
if ("HETATM".equals(field)) atom.isHetero = true;
break;
case 18:
if ("dum".equals(field)) {
atom.x = NaN;
continue;
}break;
case 75:
case 61:
siteMult = this.parseIntField();
break;
case 62:
case 47:
if (field.equalsIgnoreCase("Uiso")) {
var j = this.key2col[34];
if (j != -1) this.asc.setU(atom, 7, this.getFloatColumnData(j));
}break;
case 22:
case 23:
case 24:
case 25:
case 26:
case 27:
case 28:
case 29:
case 30:
case 31:
case 32:
case 33:
this.asc.setU(atom, (this.col2key[i] - 22) % 6, this.parseFloatField());
break;
case 35:
case 36:
case 37:
case 38:
case 39:
case 40:
this.asc.setU(atom, 6, 4);
this.asc.setU(atom, (this.col2key[i] - 35) % 6, this.parseFloatField());
break;
case 41:
case 42:
case 43:
case 44:
case 45:
case 46:
this.asc.setU(atom, 6, 0);
this.asc.setU(atom, (this.col2key[i] - 41) % 6, this.parseFloatField());
break;
case 64:
case 65:
case 66:
case 67:
case 68:
case 69:
case 76:
case 77:
case 78:
case 80:
case 81:
case 82:
case 84:
case 83:
this.haveMagneticMoments = true;
var pt = atom.vib;
if (pt == null) atom.vib = pt =  new JU.Vibration().setType(-2);
var v = this.parseFloatField();
switch (tok) {
case 84:
pt.magMoment = v;
continue;
case 83:
pt.symmform = field;
continue;
case 64:
case 67:
case 76:
case 80:
pt.x = v;
this.appendLoadNote("magnetic moment: " + this.line);
break;
case 65:
case 68:
case 77:
case 81:
pt.y = v;
break;
case 66:
case 69:
case 78:
case 82:
pt.z = v;
if (pt.length() == 0 && this.modDim == 0) {
atom.vib = null;
} else {
pt.isFractional = true;
}break;
}
break;
}
}
if (!haveCoord) continue;
if (Float.isNaN(atom.x) || Float.isNaN(atom.y) || Float.isNaN(atom.z)) {
JU.Logger.warn("atom " + atom.atomName + " has invalid/unknown coordinates");
continue;
}if (siteMult > 0 && wyckoff != null && wyckoff.length > 0) seqIdOrWyckoffCode = ((siteMult << 16)) | (wyckoff.charAt(0)).charCodeAt(0);
var strChain = componentId;
if (haveAuth) {
if (authAtom != null) atom.atomName = authAtom;
if (authComp != null) atom.group3 = authComp;
if (authSeq != -2147483648) atom.sequenceNumber = authSeq;
if (authAsym != null && this.useAuthorChainID) strChain = authAsym;
}if (strChain != null) {
this.setChainID(atom, strChain);
}if (this.maxSerial != -2147483648) this.maxSerial = Math.max(this.maxSerial, atom.sequenceNumber);
if (!this.addCifAtom(atom, id, componentId, strChain)) continue;
if ((id != null || wyckoff != null) && seqIdOrWyckoffCode > 0) {
atom.seqIdOrWyckoffCode = seqIdOrWyckoffCode | (-2147483648);
}if (this.modDim > 0 && siteMult != 0) {
atom.seqIdOrWyckoffCode |= (siteMult << 16);
}}
this.asc.setCurrentModelInfo("isCIF", Boolean.TRUE);
if (this.isMMCIF) this.setModelPDB(true);
if (this.isMMCIF && this.skipping) this.skipping = false;
return true;
}, "~B");
Clazz.defineMethod(c$, "parseCartesianField", 
function(){
return this.parseFloatField();
});
Clazz.defineMethod(c$, "addCifAtom", 
function(atom, id, componentId, strChain){
if (atom.elementSymbol == null && atom.atomName != null) atom.getElementSymbol();
if (!this.filterCIFAtom(atom, componentId)) return false;
this.setAtomCoord(atom);
if (this.isMMCIF && !this.processSubclassAtom(atom, componentId, strChain)) return false;
if (this.asc.iSet < 0) this.nextAtomSet();
this.asc.addAtomWithMappedName(atom);
if (id != null) {
this.asc.atomSymbolicMap.put(id, atom);
}this.ac++;
return true;
}, "J.adapter.smarter.Atom,~S,~S,~S");
Clazz.defineMethod(c$, "checkPDBModelField", 
function(modelField, currentModelNo){
return 0;
}, "~N,~N");
Clazz.defineMethod(c$, "processSubclassAtom", 
function(atom, assemblyId, strChain){
return true;
}, "J.adapter.smarter.Atom,~S,~S");
Clazz.defineMethod(c$, "filterCIFAtom", 
function(atom, componentId){
if (!this.filterAtom(atom, -1)) return false;
if (this.filterAssembly && this.filterReject(this.filter, "$", componentId)) return false;
if (this.configurationPtr > 0) {
if (!this.disorderAssembly.equals(this.lastDisorderAssembly)) {
this.lastDisorderAssembly = this.disorderAssembly;
this.lastAltLoc = '\0';
this.conformationIndex = this.configurationPtr;
}if (atom.altLoc != '\0') {
if (this.conformationIndex >= 0 && atom.altLoc != this.lastAltLoc) {
this.lastAltLoc = atom.altLoc;
this.conformationIndex--;
}if (this.conformationIndex != 0) {
JU.Logger.info("ignoring " + atom.atomName);
return false;
}}}return true;
}, "J.adapter.smarter.Atom,~S");
Clazz.defineMethod(c$, "processCitationListBlock", 
function(){
this.parseLoopParameters(J.adapter.readers.cif.CifReader.citationFields);
while (this.cifParser.getData()) {
var title = this.getFieldString(0);
if (!this.isNull(title)) this.appendLoadNote("TITLE: " + this.cifParser.toUnicode(title));
}
});
Clazz.defineMethod(c$, "processSymmetryOperationsLoopBlock", 
function(){
this.parseLoopParametersFor("_space_group_symop", J.adapter.readers.cif.CifReader.symmetryOperationsFields);
var n;
this.symops =  new JU.Lst();
for (n = J.adapter.readers.cif.CifReader.symmetryOperationsFields.length; --n >= 0; ) if (this.key2col[n] != -1) break;

if (n < 0) {
JU.Logger.warn("required _space_group_symop key not found");
this.skipLoop(false);
return;
}n = 0;
var isMag = false;
var sxyz = null;
var suvw = null;
var suvwMapped = null;
var suvwId = null;
while (this.cifParser.getData()) {
var ssgop = false;
var oxyz = null;
var ouvw = null;
var nn = this.cifParser.getColumnCount();
var timeRev = (this.fieldProperty(this.key2col[7]) == -1 && this.fieldProperty(this.key2col[8]) == -1 && this.fieldProperty(this.key2col[6]) == -1 ? 0 : (this.field).equals("-1") ? -1 : 1);
if (timeRev != 0) this.isMagCIF = true;
for (var i = 0; i < nn; ++i) {
var tok = this.fieldProperty(i);
var field = this.field;
switch (tok) {
case 5:
if (field.indexOf('~') >= 0) field = JU.PT.rep(field, "~", "");
case 2:
case 3:
this.modulated = true;
ssgop = true;
case 0:
case 4:
case 1:
case 15:
case 16:
case 17:
if (this.allowRotations || timeRev != 0 || ++n == 1) if (!this.modulated || ssgop) {
switch (tok) {
case 1:
case 3:
isMag = this.isMagCIF = true;
timeRev = this.getTimeReversal(field);
if (timeRev != 0) field = this.field;
break;
case 15:
this.isSpinCIF = true;
timeRev = this.getTimeReversal(field);
if (timeRev != 0) field = this.field;
oxyz = field;
if (ouvw == null) {
continue;
}case 17:
if (ouvw == null) {
this.haveSpinReferences = true;
ouvw = "u" + field;
}case 16:
if (ouvw == null) ouvw = field;
if (oxyz == null) {
continue;
}ouvw = this.parseUvwMath(ouvw);
field = oxyz + "(" + ouvw + ")";
timeRev = 0;
}
if (timeRev != 0) field += "," + (timeRev == 1 ? "m" : "-m");
field = field.$replace(';', ' ');
this.symops.addLast(field);
this.setSymmetryOperator(field);
if (this.modulated && this.modDim == 0 && this.modr != null) this.modDim = this.modr.modDim;
}break;
case 18:
suvwId = "u" + field;
if (suvwMapped == null) continue;
field = suvwMapped;
case 19:
suvwMapped = field;
if (suvwId == null) continue;
if (this.mapSpinIdToUVW == null) this.mapSpinIdToUVW =  new java.util.Hashtable();
this.mapSpinIdToUVW.put(suvwId, suvwMapped);
break;
case 9:
case 10:
case 11:
isMag = this.isMagCIF = true;
if (this.magCenterings == null) this.magCenterings =  new JU.Lst();
this.magCenterings.addLast(field);
break;
case 12:
timeRev = this.getTimeReversal(field);
sxyz = this.field;
break;
case 14:
field = "u" + field;
this.haveSpinReferences = true;
case 13:
suvw = field;
break;
}
}
if (sxyz != null) {
if (suvw != null) {
isMag = this.isSpinCIF = true;
if (this.lstSpinLattices == null) {
this.lstSpinLattices =  new JU.Lst();
}suvw = this.parseUvwMath(suvw);
this.lstSpinLattices.addLast(sxyz + "(" + suvw + ")");
suvw = suvwMapped = suvwId = null;
}sxyz = null;
}}
if (this.ms != null && !isMag) {
this.addLatticeVectors();
}});
Clazz.defineMethod(c$, "parseUvwMath", 
function(suvw){
return JU.SimpleUnitCell.parseSimpleMath(this.vwr, suvw);
}, "~S");
Clazz.defineMethod(c$, "getTimeReversal", 
function(field){
var tr = (field.endsWith(",+1") || field.endsWith(",1") ? 1 : field.endsWith(",-1") ? -1 : 0);
if (tr != 0) this.field = field.substring(0, field.lastIndexOf(','));
return tr;
}, "~S");
Clazz.defineMethod(c$, "getBondOrder", 
function(field){
switch ((field.toUpperCase().charAt(0)).charCodeAt(0)) {
default:
JU.Logger.warn("unknown CIF bond order: " + field);
case 0:
case 83:
return 1;
case 68:
return 2;
case 84:
return 3;
case 81:
return 4;
case 65:
this.haveAromatic = true;
return 515;
}
}, "~S");
Clazz.defineMethod(c$, "processGeomBondLoopBlock", 
function(){
var ok = !this.modulated && (this.isMolecular || !this.doApplySymmetry && !this.ignoreGeomBonds && !(this.stateScriptVersionInt >= 130304 && this.stateScriptVersionInt < 140403));
if (ok) {
this.parseLoopParameters(J.adapter.readers.cif.CifReader.geomBondFields);
ok = this.checkAllFieldsPresent(J.adapter.readers.cif.CifReader.geomBondFields, 2, true);
}if (!ok) {
this.skipLoop(false);
return;
}var bondCount = 0;
while (this.cifParser.getData()) {
var name1 = this.getFieldString(0);
var name2 = this.getFieldString(1);
var order = this.getBondOrder(this.getFieldString(3));
var sdist = this.getFieldString(2);
var distance = this.parseFloatStr(sdist);
if (distance == 0 || Float.isNaN(distance)) {
if (!this.iHaveFractionalCoordinates) {
var a = this.getAtomFromNameCheckCase(name1);
var b = this.getAtomFromNameCheckCase(name2);
if (a == null || b == null) {
System.err.println("ATOM_SITE atom for name " + (a != null ? name2 : b != null ? name1 : name1 + " and " + name2) + " not found");
continue;
}this.asc.addNewBondWithOrder(a.index, b.index, order);
}continue;
}var dx = this.getStandardDeviation(sdist);
bondCount++;
this.bondTypes.addLast( Clazz.newArray(-1, [name1, name2, Float.$valueOf(distance), Float.$valueOf(dx), Integer.$valueOf(order)]));
}
if (bondCount > 0) {
JU.Logger.info(bondCount + " bonds read");
if (!this.doApplySymmetry) {
this.isMolecular = true;
this.forceSymmetry(false);
}}});
Clazz.defineMethod(c$, "getStandardDeviation", 
function(sdist){
var pt = sdist.indexOf('(');
if (pt >= 0) {
var data = sdist.toCharArray();
var sdx = sdist.substring(pt + 1, data.length - 1);
var n = sdx.length;
for (var j = pt; --j >= 0; ) {
if (data[j] == '.' && --j < 0) break;
data[j] = (--n < 0 ? '0' : data[pt + 1 + n]);
}
var dx = this.parseFloatStr(String.valueOf(data));
if (!Float.isNaN(dx)) {
return dx;
}}JU.Logger.info("CifReader error reading uncertainty for " + sdist + " (set to 0.015) on line " + this.line);
return 0.015;
}, "~S");
Clazz.defineMethod(c$, "getAtomFromNameCheckCase", 
function(name){
var a = this.asc.getAtomFromName(name);
if (a == null) {
if (!this.asc.atomMapAnyCase) {
this.asc.setAtomMapAnyCase();
}a = this.asc.getAtomFromName(name.toUpperCase());
}return a;
}, "~S");
Clazz.defineMethod(c$, "setBondingAndMolecules", 
function(){
this.atoms = this.asc.atoms;
this.firstAtom = this.asc.getLastAtomSetAtomIndex();
var nat = this.asc.getLastAtomSetAtomCount();
this.ac = this.firstAtom + nat;
JU.Logger.info("CIF creating molecule for " + nat + " atoms " + (this.bondTypes.size() > 0 ? " using GEOM_BOND records" : ""));
this.bsSets =  new Array(nat);
this.symmetry = this.asc.getSymmetry();
for (var i = this.firstAtom; i < this.ac; i++) {
var ipt = this.asc.getAtomFromName(this.atoms[i].atomName).index - this.firstAtom;
if (ipt < 0) continue;
if (this.bsSets[ipt] == null) this.bsSets[ipt] =  new JU.BS();
this.bsSets[ipt].set(i - this.firstAtom);
}
if (this.isMolecular) {
this.atomRadius =  Clazz.newFloatArray (this.ac, 0);
for (var i = this.firstAtom; i < this.ac; i++) {
var elemnoWithIsotope = J.api.JmolAdapter.getElementNumber(this.atoms[i].getElementSymbol());
this.atoms[i].elementNumber = elemnoWithIsotope;
var charge = (this.atoms[i].formalCharge == -2147483648 ? 0 : this.atoms[i].formalCharge);
if (elemnoWithIsotope > 0) this.atomRadius[i] = J.api.JmolAdapter.getBondingRadius(elemnoWithIsotope, charge);
}
this.bsConnected =  new Array(this.ac);
for (var i = this.firstAtom; i < this.ac; i++) this.bsConnected[i] =  new JU.BS();

this.bsMolecule =  new JU.BS();
this.bsExclude =  new JU.BS();
}var isFirst = true;
this.bsBondDuplicates =  new JU.BS();
while (this.createBonds(isFirst)) {
isFirst = false;
}
if (this.isMolecular && this.iHaveFractionalCoordinates && !this.bsMolecule.isEmpty()) {
var bs = this.asc.getBSAtoms(this.asc.bsAtoms == null ? this.firstAtom : 0);
bs.clearBits(this.firstAtom, this.ac);
bs.or(this.bsMolecule);
bs.andNot(this.bsExclude);
for (var i = this.firstAtom; i < this.ac; i++) {
if (bs.get(i)) this.symmetry.toCartesian(this.atoms[i], true);
 else if (this.debugging) JU.Logger.debug(this.molecularType + " removing " + i + " " + this.atoms[i].atomName + " " + this.atoms[i]);
}
this.asc.setCurrentModelInfo("unitCellParams", null);
if (this.nMolecular++ == this.asc.iSet) {
this.asc.clearGlobalBoolean(0);
this.asc.clearGlobalBoolean(1);
this.asc.clearGlobalBoolean(2);
}}if (this.bondTypes.size() > 0) this.asc.setCurrentModelInfo("hasBonds", Boolean.TRUE);
this.bondTypes.clear();
this.atomRadius = null;
this.bsSets = null;
this.bsConnected = null;
this.bsMolecule = null;
this.bsExclude = null;
});
Clazz.defineMethod(c$, "fixAtomForBonding", 
function(pt, i){
pt.setT(this.atoms[i]);
if (this.iHaveFractionalCoordinates) this.symmetry.toCartesian(pt, true);
}, "JU.P3,~N");
Clazz.defineMethod(c$, "createBonds", 
function(doInit){
var list = "";
var haveH = false;
for (var i = this.bondTypes.size(); --i >= 0; ) {
if (this.bsBondDuplicates.get(i)) continue;
var o = this.bondTypes.get(i);
var distance = (o[2]).floatValue();
var dx = (o[3]).floatValue();
var order = (o[4]).intValue();
var a1 = this.getAtomFromNameCheckCase(o[0]);
var a2 = this.getAtomFromNameCheckCase(o[1]);
if (a1 == null || a2 == null) {
System.err.println("CifReader checking GEOM_BOND " + o[0] + "-" + o[1] + " found " + a1 + " " + a2);
continue;
}if (Float.isNaN(a1.x) || Float.isNaN(a2.x)) {
System.err.println("CifReader checking GEOM_BOND " + o[0] + "-" + o[1] + " found x coord NaN");
continue;
}var iatom1 = a1.index;
var iatom2 = a2.index;
if (doInit) {
var key = ";" + iatom1 + ";" + iatom2 + ";" + distance;
if (list.indexOf(key) >= 0) {
this.bsBondDuplicates.set(i);
continue;
}list += key;
}var bs1 = this.bsSets[iatom1 - this.firstAtom];
var bs2 = this.bsSets[iatom2 - this.firstAtom];
if (bs1 == null || bs2 == null) continue;
if (this.atoms[iatom1].elementNumber == 1 || this.atoms[iatom2].elementNumber == 1) haveH = true;
for (var j = bs1.nextSetBit(0); j >= 0; j = bs1.nextSetBit(j + 1)) {
for (var k = bs2.nextSetBit(0); k >= 0; k = bs2.nextSetBit(k + 1)) {
if ((!this.isMolecular || !this.bsConnected[j + this.firstAtom].get(k)) && this.checkBond(this.atoms[j + this.firstAtom], this.atoms[k + this.firstAtom], distance, dx)) {
this.addNewBond(j + this.firstAtom, k + this.firstAtom, order);
}}
}
}
if (!this.iHaveFractionalCoordinates) return false;
if (this.bondTypes.size() > 0 && !haveH) for (var i = this.firstAtom; i < this.ac; i++) if (this.atoms[i].elementNumber == 1) {
var checkAltLoc = (this.atoms[i].altLoc != '\0');
for (var k = this.firstAtom; k < this.ac; k++) if (k != i && this.atoms[k].elementNumber != 1 && (!checkAltLoc || this.atoms[k].altLoc == '\0' || this.atoms[k].altLoc == this.atoms[i].altLoc)) {
if (!this.bsConnected[i].get(k) && this.checkBond(this.atoms[i], this.atoms[k], 1.1, 0)) this.addNewBond(i, k, 1);
}
}
if (!this.isMolecular) return false;
if (doInit) for (var i = this.firstAtom; i < this.ac; i++) if (this.atoms[i].atomSite + this.firstAtom == i && !this.bsMolecule.get(i)) this.setBs(this.atoms, i, this.bsConnected, this.bsMolecule);

var bondTolerance = this.vwr.getFloat(570425348);
var bsBranch =  new JU.BS();
var cart1 =  new JU.P3();
var cart2 =  new JU.P3();
var nFactor = 2;
for (var i = this.firstAtom; i < this.ac; i++) if (!this.bsMolecule.get(i) && !this.bsExclude.get(i)) for (var j = this.bsMolecule.nextSetBit(0); j >= 0; j = this.bsMolecule.nextSetBit(j + 1)) if (this.checkBondDistance(this.atoms[j], this.atoms[i], this.atomRadius[i] + this.atomRadius[j] + bondTolerance, 0, nFactor, nFactor, nFactor, this.ptOffset)) {
this.setBs(this.atoms, i, this.bsConnected, bsBranch);
for (var k = bsBranch.nextSetBit(0); k >= 0; k = bsBranch.nextSetBit(k + 1)) {
this.atoms[k].add(this.ptOffset);
this.fixAtomForBonding(cart1, k);
var bs = this.bsSets[this.asc.getAtomIndex(this.atoms[k].atomName) - this.firstAtom];
if (bs != null) for (var ii = bs.nextSetBit(0); ii >= 0; ii = bs.nextSetBit(ii + 1)) {
if (ii + this.firstAtom == k) continue;
this.fixAtomForBonding(cart2, ii + this.firstAtom);
if (cart2.distance(cart1) < 0.1) {
this.bsExclude.set(k);
break;
}}
this.bsMolecule.set(k);
}
return true;
}

return false;
}, "~B");
Clazz.defineMethod(c$, "checkBond", 
function(a, b, distance, dx){
var ret;
if (this.iHaveFractionalCoordinates) {
ret = this.checkBondDistance(a, b, distance, dx, 0, 0, 0, this.ptOffset);
} else {
var d = a.distance(b);
ret = (dx > 0 ? Math.abs(d - distance) <= dx : d <= distance && d > 0.1);
}return ret;
}, "J.adapter.smarter.Atom,J.adapter.smarter.Atom,~N,~N");
Clazz.defineMethod(c$, "checkBondDistance", 
function(f1, f2, distance, slop, iRange, jRange, kRange, ptOffset){
var p1 = JU.P3.newP(f1);
this.symmetry.toCartesian(p1, true);
for (var i = -iRange; i <= iRange; i++) for (var j = -jRange; j <= jRange; j++) for (var k = -kRange; k <= kRange; k++) {
ptOffset.set(f2.x + i, f2.y + j, f2.z + k);
this.symmetry.toCartesian(ptOffset, true);
var d = p1.distance(ptOffset);
if (slop > 0 ? Math.abs(d - distance) <= slop : d <= distance && d > 0.1) {
ptOffset.set(i, j, k);
return true;
}}


return false;
}, "JU.P3,JU.P3,~N,~N,~N,~N,~N,JU.P3");
Clazz.defineMethod(c$, "addNewBond", 
function(i, j, order){
this.asc.addNewBondWithOrder(i, j, order);
if (!this.isMolecular) return;
this.bsConnected[i].set(j);
this.bsConnected[j].set(i);
}, "~N,~N,~N");
Clazz.defineMethod(c$, "setBs", 
function(atoms, iatom, bsBonds, bs){
var bsBond = bsBonds[iatom];
bs.set(iatom);
for (var i = bsBond.nextSetBit(0); i >= 0; i = bsBond.nextSetBit(i + 1)) {
if (!bs.get(i)) this.setBs(atoms, i, bsBonds, bs);
}
}, "~A,~N,~A,JU.BS");
Clazz.defineMethod(c$, "checkSubclassSymmetry", 
function(){
return this.doCheckUnitCell;
});
Clazz.defineMethod(c$, "checkAllFieldsPresent", 
function(keys, lastKey, critical){
for (var i = (lastKey < 0 ? keys.length : lastKey); --i >= 0; ) if (this.key2col[i] == -1) {
if (critical) JU.Logger.warn("CIF reader missing property: " + keys[i]);
return false;
}
return true;
}, "~A,~N,~B");
Clazz.defineMethod(c$, "isNull", 
function(key){
return key.equals("\0");
}, "~S");
Clazz.defineMethod(c$, "skipLoop", 
function(doReport){
if (this.isLoop) this.cifParser.skipLoop(doReport);
}, "~B");
c$.fullTrim = Clazz.defineMethod(c$, "fullTrim", 
function(s){
var str = s;
var pt0 = -1;
var pt1 = str.length;
while (++pt0 < pt1 && JU.PT.isWhitespace(str.charAt(pt0))) {
}
while (--pt1 > pt0 && JU.PT.isWhitespace(str.charAt(pt1))) {
}
return str.substring(pt0, pt1 + 1);
}, "~O");
Clazz.defineMethod(c$, "isFieldValid", 
function(){
return ((this.field).length > 0 && (this.firstChar = (this.field).charAt(0)) != '\0');
});
Clazz.defineMethod(c$, "parseIntField", 
function(){
return this.parseIntStr(this.field);
});
Clazz.defineMethod(c$, "parseFloatField", 
function(){
return this.parseFloatStr(this.field);
});
Clazz.defineMethod(c$, "getData", 
function(){
this.key = this.cifParser.getTokenPeeked();
if (!this.continueWith(this.key)) return false;
if (this.skipKey(this.key)) {
this.field = this.cifParser.skipNextToken();
} else {
this.field = this.cifParser.getNextToken();
}if (this.field == null) {
JU.Logger.warn("CIF ERROR ? end of file; data missing: " + this.key);
return false;
}var field = this.field;
return (field.length == 0 || field.charAt(0) != '\0');
});
Clazz.defineMethod(c$, "parseLoopParameters", 
function(fieldNames){
this.cifParser.parseDataBlockParameters(fieldNames, this.isLoop ? null : this.key0, this.field, this.key2col, this.col2key);
}, "~A");
Clazz.defineMethod(c$, "getFieldString", 
function(type){
var i = this.key2col[type];
return (i <= -1 ? "\0" : this.cifParser.getColumnData(i));
}, "~N");
Clazz.defineMethod(c$, "skipLoopKeyword", 
function(){
this.cifParser.getTokenPeeked();
});
Clazz.defineMethod(c$, "isLoopKey", 
function(){
return this.key.startsWith("loop_");
});
Clazz.defineMethod(c$, "getFloatColumnData", 
function(i){
return this.parseFloatStr(this.cifParser.getColumnData(i));
}, "~N");
Clazz.declareInterface(J.adapter.readers.cif.CifReader, "Parser");
c$.TransformFields =  Clazz.newArray(-1, ["x[1][1]", "x[1][2]", "x[1][3]", "r[1]", "x[2][1]", "x[2][2]", "x[2][3]", "r[2]", "x[3][1]", "x[3][2]", "x[3][3]", "r[3]"]);
c$.atomTypeFields =  Clazz.newArray(-1, ["_atom_type_symbol", "_atom_type_oxidation_number", "_atom_type_radius_bond"]);
c$.jmolAtomFields =  Clazz.newArray(-1, ["_jmol_atom_index", "_jmol_atom_name", "_jmol_atom_site_label"]);
c$.atomFields =  Clazz.newArray(-1, ["*_type_symbol", "*_label", "*_auth_atom_id", "*_fract_x", "*_fract_y", "*_fract_z", "*_cartn_x", "*_cartn_y", "*_cartn_z", "*_occupancy", "*_b_iso_or_equiv", "*_auth_comp_id", "*_auth_asym_id", "*_auth_seq_id", "*_pdbx_pdb_ins_code", "*_label_alt_id", "*_group_pdb", "*_pdbx_pdb_model_num", "*_calc_flag", "*_disorder_group", "*_aniso_label", "*_anisotrop_id", "*_aniso_u_11", "*_aniso_u_22", "*_aniso_u_33", "*_aniso_u_12", "*_aniso_u_13", "*_aniso_u_23", "*_anisotrop_u[1][1]", "*_anisotrop_u[2][2]", "*_anisotrop_u[3][3]", "*_anisotrop_u[1][2]", "*_anisotrop_u[1][3]", "*_anisotrop_u[2][3]", "*_u_iso_or_equiv", "*_aniso_b_11", "*_aniso_b_22", "*_aniso_b_33", "*_aniso_b_12", "*_aniso_b_13", "*_aniso_b_23", "*_aniso_beta_11", "*_aniso_beta_22", "*_aniso_beta_33", "*_aniso_beta_12", "*_aniso_beta_13", "*_aniso_beta_23", "*_adp_type", "_chem_comp_atom_comp_id", "_chem_comp_atom_atom_id", "_chem_comp_atom_type_symbol", "_chem_comp_atom_charge", "_chem_comp_atom_model_cartn_x", "_chem_comp_atom_model_cartn_y", "_chem_comp_atom_model_cartn_z", "_chem_comp_atom_pdbx_model_cartn_x_ideal", "_chem_comp_atom_pdbx_model_cartn_y_ideal", "_chem_comp_atom_pdbx_model_cartn_z_ideal", "*_disorder_assembly", "*_label_asym_id", "*_subsystem_code", "*_symmetry_multiplicity", "*_thermal_displace_type", "*_moment_label", "*_moment_crystalaxis_mx", "*_moment_crystalaxis_my", "*_moment_crystalaxis_mz", "*_moment_crystalaxis_x", "*_moment_crystalaxis_y", "*_moment_crystalaxis_z", "*_id", "*_label_seq_id", "*_label_comp_id", "*_label_atom_id", "*_wyckoff_label", "*_site_symmetry_multiplicity", "*_moment_spinaxis_u", "*_moment_spinaxis_v", "*_moment_spinaxis_w", "*_spin_moment_label", "*_spin_moment_axis_u", "*_spin_moment_axis_v", "*_spin_moment_axis_w", "*_spin_moment_symmform_uvw", "*_spin_moment_magnitude", "*_spin_moment_spherical_azimuthal", "*_spin_moment_spherical_polar"]);
c$.citationFields =  Clazz.newArray(-1, ["_citation_title"]);
c$.symmetryOperationsFields =  Clazz.newArray(-1, ["*_operation_xyz", "*_magn_operation_xyz", "*_ssg_operation_algebraic", "*_magn_ssg_operation_algebraic", "_symmetry_equiv_pos_as_xyz", "_symmetry_ssg_equiv_pos_as_xyz", "*_magn_operation_timereversal", "*_magn_ssg_operation_timereversal", "*_operation_timereversal", "*_magn_centering_xyz", "*_magn_ssg_centering_algebraic", "*_magn_ssg_centering_xyz", "*_spin_lattice_xyzt", "*_spin_lattice_uvw", "*_spin_lattice_uvw_id", "*_spin_operation_xyzt", "*_spin_operation_uvw", "*_spin_operation_uvw_id", "*_spin_upart_id", "*_spin_upart_uvw"]);
c$.geomBondFields =  Clazz.newArray(-1, ["_geom_bond_atom_site_label_1", "_geom_bond_atom_site_label_2", "_geom_bond_distance", "_ccdc_geom_bond_type"]);
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
