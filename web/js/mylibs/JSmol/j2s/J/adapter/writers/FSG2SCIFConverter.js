Clazz.declarePackage("J.adapter.writers");
Clazz.load(["J.adapter.writers.CIFWriter", "JU.V3"], "J.adapter.writers.FSG2SCIFConverter", ["JU.BS", "$.Quat"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.scifInfo = null;
this.spinIndex = null;
Clazz.instantialize(this, arguments);}, J.adapter.writers, "FSG2SCIFConverter", J.adapter.writers.CIFWriter);
Clazz.defineMethod(c$, "prepareAtomSet", 
function(bs){
Clazz.superCall(this, J.adapter.writers.FSG2SCIFConverter, "prepareAtomSet", [bs]);
this.modelInfo = this.data[0];
this.scifInfo = this.data[1];
}, "JU.BS");
Clazz.overrideMethod(c$, "writeHeader", 
function(sb){
var axis = null;
var angle = 0;
var m3 = this.modelInfo.get("spinRotationMatrixApplied");
if (m3 != null) {
var q = JU.Quat.newM(m3);
axis = q.getNormalDirected(J.adapter.writers.FSG2SCIFConverter.v0);
angle = q.getThetaDirectedV(J.adapter.writers.FSG2SCIFConverter.v0);
if (Math.abs(angle) < 1) axis = null;
}for (var i = 0; i < J.adapter.writers.FSG2SCIFConverter.headerKeys.length; i++) {
var type = this.scifInfo.get("configuration");
var s;
switch (i) {
case 0:
s = this.scifInfo.get("spinFrame");
break;
case 1:
s = (type.equals("Collinear") ? "1,0,0" : ".");
break;
case 2:
s = (type.equals("Coplanar") ? "0,0,1" : ".");
break;
case 3:
s = (axis == null ? "?" : "[ " + axis.x + " " + axis.y + " " + axis.z + " ]");
break;
case 4:
s = (angle == 0 ? "?" : this.clean(angle));
break;
case 5:
s = this.scifInfo.get("fsgID");
break;
case 6:
s = "\n;\n" + this.scifInfo.get("simpleName") + "\n;\n";
break;
default:
s = "??";
break;
}
J.adapter.writers.CIFWriter.appendField(sb, J.adapter.writers.FSG2SCIFConverter.headerKeys[i], 45);
sb.append(s).append("\n");
}
}, "JU.SB");
Clazz.overrideMethod(c$, "writeOperations", 
function(sb){
var refsOp = this.scifInfo.get("G0_operationURefs");
var refsLat = this.scifInfo.get("G0_spinLatticeURefs");
var bsUparts = this.getReferences(refsOp, refsLat);
var timeRev = this.scifInfo.get("SCIF_spinListTR");
this.addOps(sb, "\nloop_\n_space_group_symop_spin_operation.id\n_space_group_symop_spin_operation.xyzt\n_space_group_symop_spin_operation.uvw_id\n", "G0_operations", refsOp, timeRev);
this.addOps(sb, "\nloop_\n_space_group_symop_spin_lattice.id\n_space_group_symop_spin_lattice.xyzt\n_space_group_symop_spin_lattice.uvw_id\n", "G0_spinLattice", refsLat, timeRev);
sb.append("\nloop_\n_space_group_symop_spin_Upart.id\n_space_group_symop_spin_Upart.time_reversal\n_space_group_symop_spin_Upart.uvw\n");
var uparts = this.scifInfo.get("SCIF_spinList");
for (var i = bsUparts.nextSetBit(0); i >= 0; i = bsUparts.nextSetBit(i + 1)) {
J.adapter.writers.CIFWriter.appendField(sb, "" + (this.spinIndex[i]), 3);
J.adapter.writers.CIFWriter.appendField(sb, "" + timeRev[i], 3);
J.adapter.writers.CIFWriter.appendField(sb, uparts.get(i), 30);
sb.append("\n");
}
}, "JU.SB");
Clazz.defineMethod(c$, "getReferences", 
function(refsOp, refsLat){
var bs =  new JU.BS();
for (var i = refsOp.length; --i >= 0; ) bs.set(refsOp[i]);

if (refsLat != null) for (var i = refsLat.length; --i >= 0; ) bs.set(refsLat[i]);

this.spinIndex =  Clazz.newIntArray (bs.length(), 0);
for (var pt = 0, i = bs.nextSetBit(0); i >= 0; i = bs.nextSetBit(i + 1)) {
this.spinIndex[i] = ++pt;
}
return bs;
}, "~A,~A");
c$.timeRevValue = Clazz.defineMethod(c$, "timeRevValue", 
function(t){
return (t < 0 ? "-1" : "+1");
}, "~N");
Clazz.defineMethod(c$, "addOps", 
function(sb, loopKeys, opsKey, refs, timeReversal){
var ops = this.scifInfo.get(opsKey);
if (ops == null) return;
sb.append(loopKeys);
for (var i = 0, n = ops.size(); i < n; i++) {
var xyz = ops.get(i).substring(0, ops.get(i).indexOf('('));
J.adapter.writers.CIFWriter.appendField(sb, "" + (i + 1), 3);
J.adapter.writers.CIFWriter.appendField(sb, xyz + "," + J.adapter.writers.FSG2SCIFConverter.timeRevValue(timeReversal[refs[i]]), 30);
J.adapter.writers.CIFWriter.appendField(sb, "" + this.spinIndex[refs[i]], 3);
sb.append("\n");
}
}, "JU.SB,~S,~S,~A,~A");
Clazz.defineMethod(c$, "writeAtomSite", 
function(sb){
var natoms = Clazz.superCall(this, J.adapter.writers.FSG2SCIFConverter, "writeAtomSite", [sb]);
sb.append("\nloop_\n_atom_site_spin_moment.label\n_atom_site_spin_moment.axis_u\n_atom_site_spin_moment.axis_v\n_atom_site_spin_moment.axis_w\n_atom_site_spin_moment.symmform_uvw\n_atom_site_spin_moment.magnitude\n");
for (var i = this.bsOut.nextSetBit(0), p = 0; i >= 0; i = this.bsOut.nextSetBit(i + 1)) {
var a = this.atoms[i];
var label = this.atomLabels[p++];
var m = a.getVibrationVector();
if (m == null || m.magMoment == 0) continue;
J.adapter.writers.CIFWriter.appendField(sb, label, 5);
var v = (m.v0 == null ? m : m.v0);
this.append3(sb, v);
sb.append(" ?");
sb.append(" ").append(this.clean(v.length()));
sb.append("\n");
}
this.jmol_atoms = null;
return natoms;
}, "JU.SB");
c$.v0 = JU.V3.new3(3.14159, 2.71828, 1.4142);
c$.headerKeys =  Clazz.newArray(-1, ["_space_group_spin.transform_spinframe_P_abc", "_space_group_spin.collinear_direction", "_space_group_spin.coplanar_perp_uvw", "_space_group_spin.rotation_axis_cartn", "_space_group_spin.rotation_angle", "_space_group_spin.number_SpSG_Chen", "_space_group_spin.name_SpSG_Chen"]);
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
