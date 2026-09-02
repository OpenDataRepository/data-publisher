Clazz.declarePackage("J.adapter.writers");
Clazz.load(["J.adapter.writers.XtlWriter", "J.api.JmolWriter", "JU.P3"], "J.adapter.writers.CIFWriter", ["JU.BS", "$.PT", "$.SB", "J.api.Interface", "JU.BSUtil", "JV.Viewer"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.vwr = null;
this.oc = null;
this.data = null;
this.isP1 = false;
this.isCIF2 = false;
this.modelIndex = 0;
this.haveCustom = false;
this.uc = null;
this.bsOut = null;
this.atoms = null;
this.nops = 0;
this.atomLabels = null;
this.jmol_atoms = null;
this.modelInfo = null;
Clazz.instantialize(this, arguments);}, J.adapter.writers, "CIFWriter", J.adapter.writers.XtlWriter, J.api.JmolWriter);
Clazz.makeConstructor(c$, 
function(){
Clazz.superConstructor (this, J.adapter.writers.CIFWriter, []);
});
Clazz.overrideMethod(c$, "set", 
function(viewer, oc, data){
this.vwr = viewer;
this.oc = (oc == null ? this.vwr.getOutputChannel(null, null) : oc);
this.data = data;
this.isP1 = (data != null && data.length > 0 && "P1".equals(data[0]));
}, "JV.Viewer,JU.OC,~A");
Clazz.overrideMethod(c$, "write", 
function(bs){
try {
bs = (bs == null ? this.vwr.bsA() : JU.BSUtil.copy(bs));
if (bs.isEmpty()) return "";
this.modelInfo = this.vwr.ms.getModelAuxiliaryInfo(this.vwr.ms.at[bs.nextSetBit(0)].mi);
var info = this.modelInfo.get("scifInfo");
var writer;
if (info == null) {
writer = this;
} else {
writer = J.api.Interface.getInterface("J.adapter.writers.FSG2SCIFConverter", this.vwr, "cifwriter");
writer.set(this.vwr, null,  Clazz.newArray(-1, [this.modelInfo, info]));
this.isCIF2 = true;
}writer.prepareAtomSet(bs);
var sb =  new JU.SB();
if (this.isCIF2) {
sb.append("#\\#CIF_2.0\n");
}sb.append("## CIF file created by Jmol " + JV.Viewer.getJmolVersion());
if (writer.haveCustom) {
sb.append(JU.PT.rep("\n" + this.uc.getUnitCellInfo(false), "\n", "\n##Jmol_orig "));
}sb.append("\ndata_global\n\n");
writer.writeHeader(sb);
writer.writeParams(sb);
writer.writeOperations(sb);
var nAtoms = writer.writeAtomSite(sb);
if (writer.jmol_atoms != null) {
sb.appendSB(writer.jmol_atoms);
sb.append("\n_jmol_atom_count   " + nAtoms);
}sb.append("\n_jmol_precision    " + writer.precision + "\n");
this.oc.append(sb.toString());
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
if (!JV.Viewer.isJS) e.printStackTrace();
} else {
throw e;
}
}
return this.toString();
}, "JU.BS");
Clazz.defineMethod(c$, "prepareAtomSet", 
function(bs){
this.atoms = this.vwr.ms.at;
this.modelIndex = this.atoms[bs.nextSetBit(0)].mi;
var n0 = bs.cardinality();
bs.and(this.vwr.getModelUndeletedAtomsBitSet(this.modelIndex));
if (n0 < bs.cardinality()) {
System.err.println("CIFWriter Warning: Atoms not in model " + (this.modelIndex + 1) + " ignored");
}this.uc = this.vwr.ms.getUnitCell(this.modelIndex);
this.haveUnitCell = (this.uc != null);
if (!this.haveUnitCell) this.uc = this.vwr.getSymTemp().setUnitCellFromParams(null, false, 0.00001);
this.nops = (this.isP1 ? 0 : this.uc.getSpaceGroupOperationCount());
this.slop = this.uc.getPrecision();
this.precision = Clazz.doubleToInt(-Math.log10(this.slop));
this.isHighPrecision = (this.slop == 1.0E-12);
var fractionalOffset = (this.uc.getFractionalOffset(true) != null);
var fset;
this.haveCustom = (fractionalOffset || (fset = this.uc.getUnitCellMultiplier()) != null && (fset.z == 1 ? !fset.equals(J.adapter.writers.CIFWriter.fset0) : fset.z != 0));
var ucm = this.uc.getUnitCellMultiplied();
this.isP1 = (this.isP1 || ucm !== this.uc || fractionalOffset || this.uc.getSpaceGroupOperationCount() < 2);
this.uc = ucm;
var modelAU = (!this.haveUnitCell ? bs : this.isP1 ? this.uc.removeDuplicates(this.vwr.ms, bs, false) : this.vwr.ms.am[this.modelIndex].bsAsymmetricUnit);
if (modelAU == null) {
this.bsOut = bs;
} else {
this.bsOut =  new JU.BS();
this.bsOut.or(modelAU);
this.bsOut.and(bs);
}this.vwr.setErrorMessage(null, " (" + this.bsOut.cardinality() + " atoms)");
}, "JU.BS");
Clazz.defineMethod(c$, "writeHeader", 
function(sb){
var hallName;
var hmName;
var ita;
if (this.isP1) {
ita = "1";
hallName = "P 1";
hmName = "P1";
} else {
this.uc.getSpaceGroupInfo(this.vwr.ms, null, this.modelIndex, true, null);
ita = this.uc.geCIFWriterValue("ita");
hallName = this.uc.geCIFWriterValue("HallSymbol");
hmName = this.uc.geCIFWriterValue("HermannMauguinSymbol");
}this.appendKey(sb, "_space_group_IT_number", 27).append(ita == null ? "?" : ita.toString());
this.appendKey(sb, "_space_group_name_Hall", 27).append(hallName == null || hallName.equals("?") ? "?" : "'" + hallName + "'");
this.appendKey(sb, "_space_group_name_H-M_alt", 27).append(hmName == null ? "?" : "'" + hmName + "'");
}, "JU.SB");
Clazz.defineMethod(c$, "writeOperations", 
function(sb){
sb.append("\n\nloop_\n_space_group_symop_id\n_space_group_symop_operation_xyz");
if (this.nops == 0) {
sb.append("\n1 x,y,z");
} else {
var symops = this.uc.getSymmetryOperations();
for (var i = 0; i < this.nops; i++) {
var sop = symops[i].getXyz(true);
sb.append("\n").appendI(i + 1).append("\t").append(sop.replaceAll(" ", ""));
}
}}, "JU.SB");
Clazz.defineMethod(c$, "writeParams", 
function(sb){
var params = this.uc.getUnitCellAsArray(false);
this.appendKey(sb, "_cell_length_a", 27).append(this.cleanT(params[0]));
this.appendKey(sb, "_cell_length_b", 27).append(this.cleanT(params[1]));
this.appendKey(sb, "_cell_length_c", 27).append(this.cleanT(params[2]));
this.appendKey(sb, "_cell_angle_alpha", 27).append(this.cleanT(params[3]));
this.appendKey(sb, "_cell_angle_beta", 27).append(this.cleanT(params[4]));
this.appendKey(sb, "_cell_angle_gamma", 27).append(this.cleanT(params[5]));
sb.append("\n");
}, "JU.SB");
Clazz.defineMethod(c$, "writeAtomSite", 
function(sb){
var elements = "";
var haveOccupancy = false;
var occ = (this.haveUnitCell ? this.vwr.ms.occupancies : null);
if (occ != null) {
for (var i = this.bsOut.nextSetBit(0); i >= 0; i = this.bsOut.nextSetBit(i + 1)) {
if (occ[i] != 1) {
haveOccupancy = true;
break;
}}
}var haveAltLoc = false;
for (var i = this.bsOut.nextSetBit(0); i >= 0; i = this.bsOut.nextSetBit(i + 1)) {
if (this.atoms[i].altloc != '\0') {
haveAltLoc = true;
break;
}}
var parts = (haveAltLoc ? this.vwr.getDataObj("property_part", this.bsOut, 1) : null);
var sbLength = sb.length();
sb.append("\n\nloop_\n_atom_site_label\n_atom_site_type_symbol\n_atom_site_fract_x\n_atom_site_fract_y\n_atom_site_fract_z");
if (haveAltLoc) {
sb.append("\n_atom_site_disorder_group");
}if (haveOccupancy) {
sb.append("\n_atom_site_occupancy");
} else if (!this.haveUnitCell) {
sb.append("\n_atom_site_Cartn_x\n_atom_site_Cartn_y\n_atom_site_Cartn_z");
}sb.append("\n");
this.jmol_atoms =  new JU.SB();
this.jmol_atoms.append("\n\nloop_\n_jmol_atom_index\n_jmol_atom_name\n_jmol_atom_site_label\n");
var nAtoms = 0;
var p =  new JU.P3();
var elemNums =  Clazz.newIntArray (130, 0);
this.atomLabels =  new Array(this.bsOut.cardinality());
for (var pi = 0, labeli = 0, i = this.bsOut.nextSetBit(0); i >= 0; i = this.bsOut.nextSetBit(i + 1)) {
var a = this.atoms[i];
p.setT(a);
if (this.haveUnitCell) {
this.uc.toFractional(p, !this.isP1);
}nAtoms++;
var name = a.getAtomName();
var sym = a.getElementSymbol();
var elemno = a.getElementNumber();
var key = sym + "\n";
if (elements.indexOf(key) < 0) elements += key;
var label = sym + ++elemNums[elemno];
J.adapter.writers.CIFWriter.appendField(sb, label, 5);
J.adapter.writers.CIFWriter.appendField(sb, sym, 3);
this.atomLabels[labeli++] = label;
this.append3(sb, p);
if (haveAltLoc) {
sb.append(" ");
var sdis;
if (parts != null) {
var part = Clazz.floatToInt(parts[pi++]);
sdis = (part == 0 ? "." : "" + part);
} else {
sdis = "" + (a.altloc == '\0' ? '.' : a.altloc);
}sb.append(sdis);
}if (haveOccupancy) sb.append(" ").append(this.clean(occ[i] / 100));
 else if (!this.haveUnitCell) this.append3(sb, a);
sb.append("\n");
J.adapter.writers.CIFWriter.appendField(this.jmol_atoms, "" + a.getIndex(), 3);
this.writeChecked(this.jmol_atoms, name);
J.adapter.writers.CIFWriter.appendField(this.jmol_atoms, label, 5);
this.jmol_atoms.append("\n");
}
if (nAtoms > 0) {
sb.append("\nloop_\n_atom_type_symbol\n").append(elements).append("\n");
} else {
sb.setLength(sbLength);
this.jmol_atoms = null;
}return nAtoms;
}, "JU.SB");
c$.appendField = Clazz.defineMethod(c$, "appendField", 
function(sb, val, width){
sb.append(JU.PT.formatS(val, width, 0, true, false)).append(" ");
}, "JU.SB,~S,~N");
Clazz.defineMethod(c$, "append3", 
function(sb, a){
sb.append(this.clean(a.x)).append(this.clean(a.y)).append(this.clean(a.z));
}, "JU.SB,JU.T3");
Clazz.defineMethod(c$, "writeChecked", 
function(output, val){
if (val == null || val.length == 0) {
output.append(". ");
return false;
}var escape = val.charAt(0) == '_';
var escapeCharStart = "'";
var escapeCharEnd = "' ";
var hasWhitespace = false;
var hasSingle = false;
var hasDouble = false;
for (var i = 0; i < val.length; i++) {
var c = val.charAt(i);
switch ((c).charCodeAt(0)) {
case 9:
case 32:
hasWhitespace = true;
break;
case 10:
this.writeMultiline(output, val);
return true;
case 34:
if (hasSingle) {
this.writeMultiline(output, val);
return true;
}hasDouble = true;
escape = true;
escapeCharStart = "'";
escapeCharEnd = "' ";
break;
case 39:
if (hasDouble) {
this.writeMultiline(output, val);
return true;
}escape = true;
hasSingle = true;
escapeCharStart = "\"";
escapeCharEnd = "\" ";
break;
}
}
var fst = val.charAt(0);
if (!escape && (fst == '#' || fst == '$' || fst == ';' || fst == '[' || fst == ']' || hasWhitespace)) {
escapeCharStart = "'";
escapeCharEnd = "' ";
escape = true;
}if (escape) {
output.append(escapeCharStart).append(val).append(escapeCharEnd);
} else {
output.append(val).append(" ");
}return false;
}, "JU.SB,~S");
Clazz.defineMethod(c$, "writeMultiline", 
function(output, val){
output.append("\n;").append(val).append("\n;\n");
}, "JU.SB,~S");
Clazz.defineMethod(c$, "appendKey", 
function(sb, key, width){
return sb.append("\n").append(JU.PT.formatS(key, width, 0, true, false));
}, "JU.SB,~S,~N");
Clazz.defineMethod(c$, "toString", 
function(){
return (this.oc == null ? "" : this.oc.toString());
});
c$.fset0 = JU.P3.new3(555, 555, 1);
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
