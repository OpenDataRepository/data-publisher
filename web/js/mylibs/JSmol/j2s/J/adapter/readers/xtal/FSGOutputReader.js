Clazz.declarePackage("J.adapter.readers.xtal");
Clazz.load(["J.adapter.readers.cif.CifReader", "JU.P3"], "J.adapter.readers.xtal.FSGOutputReader", ["java.util.Hashtable", "JU.Lst", "$.M3", "$.M4", "$.PT", "$.Rdr", "$.SB", "J.adapter.smarter.Atom", "J.api.JmolAdapter", "JS.SymmetryOperation", "JU.BSUtil", "$.Logger", "$.Vibration"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.elementNumbers = null;
this.$spinOnly = false;
this.convertToABC = true;
this.json = null;
this.configuration = null;
this.isCoplanar = false;
this.firstTranslation = 0;
this.$spinFrame = null;
this.fullName = null;
this.readingSCIF = false;
this.scifOutputs = null;
this.$loadNote = null;
this.modelNames = null;
this.modelSCIF = null;
this.p2 = null;
this.p1 = null;
Clazz.instantialize(this, arguments);}, J.adapter.readers.xtal, "FSGOutputReader", J.adapter.readers.cif.CifReader);
Clazz.prepareFields (c$, function(){
this.p2 =  new JU.P3();
this.p1 =  new JU.P3();
});
Clazz.overrideMethod(c$, "setup", 
function(fullPath, htParams, reader){
try {
this.setupASCR(fullPath, htParams, reader);
this.vwr = htParams.get("vwr");
this.getJSON();
this.scifOutputs = this.json.get("scif_outputs");
this.readingSCIF = (this.scifOutputs != null);
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
e.printStackTrace();
} else {
throw e;
}
}
}, "~S,java.util.Map,~O");
Clazz.defineMethod(c$, "readData", 
function(){
if (this.readingSCIF) {
if (this.htParams.containsKey("modelNumber")) this.desiredModelNumber = (this.htParams.get("modelNumber")).intValue();
var s = "";
this.$loadNote = "";
var n = 0;
this.modelNames =  new JU.Lst();
this.modelSCIF =  new JU.Lst();
for (var e, $e = this.scifOutputs.entrySet().iterator (); $e.hasNext()&& ((e = $e.next ()) || true);) {
var name = e.getKey();
++n;
var scif = e.getValue();
var pt = scif.indexOf("\ndata_");
var pt1 = scif.indexOf('\n', pt + 1);
scif = scif.substring(0, pt + 6) + name + scif.substring(pt1);
s += scif + "\n";
if (this.desiredModelNumber < 1 || this.desiredModelNumber == n) {
this.modelNames.addLast(name);
this.modelSCIF.addLast(scif);
}}
this.reader = JU.Rdr.getBR(s);
var o = Clazz.superCall(this, J.adapter.readers.xtal.FSGOutputReader, "readData", []);
this.appendLoadNote((this.asc.iSet + 1) + " models were read from the FSG JSON");
return o;
}return Clazz.superCall(this, J.adapter.readers.xtal.FSGOutputReader, "readData", []);
});
Clazz.defineMethod(c$, "newAtomSet", 
function(){
Clazz.superCall(this, J.adapter.readers.xtal.FSGOutputReader, "newAtomSet", []);
var n = 0;
if (this.modelNames != null) {
var name = this.modelNames.removeItemAt(0);
var note = "model " + ++n + " is " + name;
this.appendLoadNote(note);
this.asc.setCurrentModelInfo("scif", this.modelSCIF.removeItemAt(0));
this.asc.setCurrentModelInfo("title", name);
}});
Clazz.defineMethod(c$, "initializeReader", 
function(){
Clazz.superCall(this, J.adapter.readers.xtal.FSGOutputReader, "initializeReader", []);
if (this.readingSCIF) {
return;
}this.convertToABC = false;
this.$spinOnly = this.checkFilterKey("SPINONLY");
this.checkNearAtoms = !this.checkFilterKey("NOSPECIAL");
if (!this.filteredPrecision) {
this.precision = 5;
this.filteredPrecision = true;
}System.out.println("FSGOutput using precision " + this.precision);
var symbols = this.getFilterWithCase("elements=");
if (symbols != null) {
var s = JU.PT.split(symbols.$replace(',', ' '), " ");
this.elementNumbers =  Clazz.newShortArray (s.length, 0);
for (var i = s.length; --i >= 0; ) {
this.elementNumbers[i] = J.api.JmolAdapter.getElementNumber(s[i]);
}
}try {
this.processOldJSON();
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
e.printStackTrace();
} else {
throw e;
}
}
this.continuing = false;
});
Clazz.defineMethod(c$, "getJSON", 
function(){
var sb =  new JU.SB();
while (this.rd() != null) sb.append(this.line);

this.json = this.vwr.parseJSONMap(sb.toString());
});
Clazz.defineMethod(c$, "processOldJSON", 
function(){
this.getHeaderInfo();
var info = J.adapter.readers.xtal.FSGOutputReader.getList(this.json, "G0_std_Cell");
this.getCellInfo(this.getListItem(info, 0));
this.configuration = this.json.get("Configuration");
this.isCoplanar = "Coplanar".equals(this.configuration);
this.addMoreUnitCellInfo("configuration=" + this.configuration);
this.readAllOperators(J.adapter.readers.xtal.FSGOutputReader.getList(this.json, "G0_std_operations"));
var symbols = this.getSymbols(this.json.get("AtomTypeDict"));
this.readAtomsAndMoments(info, symbols);
});
Clazz.defineMethod(c$, "warnSkippingOperation", 
function(xyz){
if (this.readingSCIF) Clazz.superCall(this, J.adapter.readers.xtal.FSGOutputReader, "warnSkippingOperation", [xyz]);
}, "~S");
Clazz.defineMethod(c$, "doPreSymmetry", 
function(doApplySymmetry){
if (this.readingSCIF) {
Clazz.superCall(this, J.adapter.readers.xtal.FSGOutputReader, "doPreSymmetry", [doApplySymmetry]);
return;
}var fs = this.asc.getSymmetry();
var bs = JU.BSUtil.newBitSet2(0, this.asc.ac);
var i = 0;
this.symmetry.setPrecision(1.0E-4);
while ((i = this.excludeAtoms(i, bs, fs)) >= 0) {
}
for (var n = 0, j = bs.nextSetBit(0); j >= 0; j = bs.nextSetBit(j + 1)) {
this.asc.atoms[j].atomSite = n++;
}
this.filterFsgAtoms(bs);
this.preSymmetrySetMoments();
System.out.println("FSGOutputReader using atoms " + bs);
var lst = fs.setSpinList(this.configuration);
if (lst != null) {
this.asc.setCurrentModelInfo("spinList", lst);
this.appendLoadNote(lst.size() + " spin operations -- see _M.spinList and atom.spin");
}System.out.println("FSGOutput operationCount=" + fs.getSpaceGroupOperationCount());
var info = this.getSCIFInfo(fs, lst);
this.asc.setCurrentModelInfo("scifInfo", info);
this.asc.setCurrentModelInfo("spinFrame", this.$spinFrame);
}, "~B");
Clazz.defineMethod(c$, "finalizeSubclassReader", 
function(){
if (this.readingSCIF) {
Clazz.superCall(this, J.adapter.readers.xtal.FSGOutputReader, "finalizeSubclassReader", []);
this.appendLoadNote(this.$loadNote);
this.setLoadNote();
return;
}this.asc.setNoAutoBond();
this.applySymmetryAndSetTrajectory();
this.addJmolScript("vectors on;vectors 0.15;");
this.vibsFractional = true;
var n = this.asc.getXSymmetry().setMagneticMoments(true);
this.asc.getXSymmetry().getFileSymmetry().setPrecision(1.0E-4);
this.appendLoadNote(n + " magnetic moments - use VECTORS ON/OFF or VECTOR MAX x.x or SELECT VXYZ>0");
});
Clazz.defineMethod(c$, "getSymbols", 
function(map){
if (map == null) {
return null;
}var symbols =  new Array(map.size() + 1);
for (var e, $e = map.entrySet().iterator (); $e.hasNext()&& ((e = $e.next ()) || true);) {
symbols[Integer.parseInt(e.getKey())] = e.getValue();
}
return symbols;
}, "java.util.Map");
Clazz.defineMethod(c$, "getListItem", 
function(info, i){
return info.get(i);
}, "JU.Lst,~N");
c$.getList = Clazz.defineMethod(c$, "getList", 
function(json, key){
return json.get(key);
}, "java.util.Map,~S");
Clazz.defineMethod(c$, "getHeaderInfo", 
function(){
this.fullName = this.json.get("SSG_international_symbol");
this.setSpaceGroupName(this.fixName(this.fullName));
});
Clazz.defineMethod(c$, "fixName", 
function(name){
System.out.println("FSGOutput " + name);
var pt;
while ((pt = name.indexOf("\\frac{")) >= 0) {
var pt2 = name.indexOf("{", pt + 7);
var pt3 = name.indexOf("}", pt2 + 1);
name = name.substring(0, pt) + "(" + name.substring(pt + 5, pt2) + "/" + name.substring(pt2, pt3) + ")" + name.substring(pt3);
}
name = JU.PT.rep(name, "\\pi", "\u03C0");
name = JU.PT.rep(name, "\\enspace", " ");
name = JU.PT.rep(name, "\\infty", "\u221E");
name = JU.PT.rep(name, "\\\\^", "^");
name = JU.PT.replaceAllCharacters(name, "{}", "");
return name;
}, "~S");
Clazz.defineMethod(c$, "getCellInfo", 
function(list){
this.setFractionalCoordinates(true);
for (var i = 0; i < 3; i++) {
var v = J.adapter.readers.xtal.FSGOutputReader.getPoint(this.getListItem(list, i));
this.addExplicitLatticeVector(i,  Clazz.newFloatArray(-1, [v.x, v.y, v.z]), 0);
}
}, "JU.Lst");
c$.getPoint = Clazz.defineMethod(c$, "getPoint", 
function(item){
return JU.P3.new3(J.adapter.readers.xtal.FSGOutputReader.getValue(item, 0), J.adapter.readers.xtal.FSGOutputReader.getValue(item, 1), J.adapter.readers.xtal.FSGOutputReader.getValue(item, 2));
}, "JU.Lst");
c$.getValue = Clazz.defineMethod(c$, "getValue", 
function(item, i){
return (item.get(i)).floatValue();
}, "JU.Lst,~N");
Clazz.defineMethod(c$, "readAllOperators", 
function(info){
var n = info.size();
var nops = 0;
for (var i = 0; i < n; i++) {
var op = info.get(i);
var mspin = this.readMatrix(this.getListItem(op, 0), null);
var mop = this.readMatrix(this.getListItem(op, 1), this.getListItem(op, 2));
var s = JS.SymmetryOperation.getTransformXYZ(mop) + JS.SymmetryOperation.getSpinString(mspin, true, true) + (this.isCoplanar ? "+" : "");
var iop = this.setSymmetryOperator(s);
if (JU.Logger.debugging) System.out.println("FSGOutput op[" + (i + 1) + "]=" + s + (iop < 0 ? " SKIPPED" : ""));
if (iop >= 0) {
var isTranslation = JS.SymmetryOperation.isTranslation(mop);
if (this.firstTranslation == 0 && isTranslation) this.firstTranslation = nops;
nops++;
}}
if (this.firstTranslation == 0) this.firstTranslation = nops;
System.out.println("FSGOutput G0_operationCount(initial)=" + n);
}, "JU.Lst");
Clazz.defineMethod(c$, "readMatrix", 
function(rot, trans){
if (rot == null) return null;
var r =  new JU.M3();
for (var i = 0; i < 3; i++) {
r.setRowV(i, J.adapter.readers.xtal.FSGOutputReader.getPoint(this.getListItem(rot, i)));
}
var t = (trans == null ?  new JU.P3() : J.adapter.readers.xtal.FSGOutputReader.getPoint(trans));
return JU.M4.newMV(r, t);
}, "JU.Lst,JU.Lst");
Clazz.defineMethod(c$, "readAtomsAndMoments", 
function(info, symbols){
var atoms = this.getListItem(info, 1);
var ids = this.getListItem(info, 2);
var moments = this.getListItem(info, 3);
for (var i = 0, n = atoms.size(); i < n; i++) {
var xyz = J.adapter.readers.xtal.FSGOutputReader.getPoint(this.getListItem(atoms, i));
var id = Clazz.floatToInt(J.adapter.readers.xtal.FSGOutputReader.getValue(ids, i));
var a =  new J.adapter.smarter.Atom();
a.setT(xyz);
this.setAtomCoord(a);
var moment = J.adapter.readers.xtal.FSGOutputReader.getPoint(this.getListItem(moments, i));
var mag = moment.length();
if (mag > 0) {
if (JU.Logger.debugging) System.out.println("FGSOutput moment " + i + " " + moment + " " + mag);
var v =  new JU.Vibration();
v.setType(-2);
v.setT(moment);
v.magMoment = mag;
a.vib = v;
System.out.println("FSGOutput atom/spin " + i + " " + a.vib + " " + mag);
} else {
if (this.$spinOnly) continue;
}if (symbols != null) {
a.elementSymbol = symbols[id];
} else if (this.elementNumbers != null && id <= this.elementNumbers.length) {
a.elementNumber = this.elementNumbers[id - 1];
} else {
a.elementNumber = (id + 2);
}this.asc.addAtom(a);
}
}, "JU.Lst,~A");
Clazz.defineMethod(c$, "preSymmetrySetMoments", 
function(){
var a = this.symmetry.getUnitCellInfoType(0);
var b = this.symmetry.getUnitCellInfoType(1);
var c = this.symmetry.getUnitCellInfoType(2);
for (var i = this.asc.ac; --i >= 0; ) {
var v = this.asc.atoms[i].vib;
if (v != null) this.spinCartesianToFractional(v, a, b, c);
}
});
Clazz.defineMethod(c$, "spinCartesianToFractional", 
function(v, a, b, c){
var p = JU.P3.newP(v);
this.symmetry.toFractional(v, true);
v.x *= a;
v.y *= b;
v.z *= c;
v.setV0();
v.setT(p);
}, "JU.Vibration,~N,~N,~N");
Clazz.defineMethod(c$, "filterFsgAtoms", 
function(bs){
for (var p = 0, i = bs.nextSetBit(0); i >= 0; p++, i = bs.nextSetBit(i + 1)) {
this.asc.atoms[p] = this.asc.atoms[i];
this.asc.atoms[p].index = p;
}
this.asc.atomSetAtomCounts[0] = this.asc.ac = bs.cardinality();
}, "JU.BS");
Clazz.defineMethod(c$, "getSCIFInfo", 
function(fs, spinList){
var m =  new java.util.Hashtable();
try {
System.out.println("FSGOutput SSG_international_symbol=" + this.fullName);
J.adapter.readers.xtal.FSGOutputReader.mput(m, "SSG_international_symbol", this.fullName);
System.out.println("FSGOutput simpleName=" + this.sgName);
J.adapter.readers.xtal.FSGOutputReader.mput(m, "simpleName", this.sgName);
var msgNum = this.json.get("MSG_num");
System.out.println("FSGOutput MSG_num=" + msgNum);
J.adapter.readers.xtal.FSGOutputReader.mput(m, "MSG_num", msgNum);
System.out.println("FSGOutput Configuration=" + this.configuration);
J.adapter.readers.xtal.FSGOutputReader.mput(m, "configuration", this.configuration);
var gSymbol = this.json.get("G_symbol");
System.out.println("FSGOutput G_symbol=" + gSymbol);
J.adapter.readers.xtal.FSGOutputReader.mput(m, "G_Symbol", gSymbol);
var g0Symbol = this.json.get("G0_symbol");
System.out.println("FSGOutput G0=" + g0Symbol);
J.adapter.readers.xtal.FSGOutputReader.mput(m, "G0_Symbol", g0Symbol);
var pt = g0Symbol.indexOf('(');
var g0ItaNo = Integer.parseInt(g0Symbol.substring(pt + 1, g0Symbol.length - 1));
var g0HMName = g0Symbol.substring(0, pt).trim();
J.adapter.readers.xtal.FSGOutputReader.mput(m, "G0_ItaNo", Integer.$valueOf(g0ItaNo));
J.adapter.readers.xtal.FSGOutputReader.mput(m, "G0_HMName", g0HMName);
var l0Symbol = this.json.get("L0_symbol");
System.out.println("FSGOutput L0=" + l0Symbol);
J.adapter.readers.xtal.FSGOutputReader.mput(m, "L0_Symbol", l0Symbol);
pt = l0Symbol.indexOf('(');
var l0ItaNo = Integer.parseInt(l0Symbol.substring(pt + 1, l0Symbol.length - 1));
var ik = (this.json.get("ik")).intValue();
var fsgID = "" + l0ItaNo + "." + g0ItaNo + "." + ik + ".?";
this.appendLoadNote("FSG ID " + fsgID);
J.adapter.readers.xtal.FSGOutputReader.mput(m, "fsgID", fsgID);
System.out.println("fsgID=" + fsgID);
var isPrimitive = g0HMName.charAt(0) == 'P';
J.adapter.readers.xtal.FSGOutputReader.mput(m, "G0_isPrimitive", Boolean.$valueOf(isPrimitive));
J.adapter.readers.xtal.FSGOutputReader.mput(m, "G0_atomCount", Integer.$valueOf(this.asc.ac));
System.out.println("FSGOutput G0_atomCount=" + this.asc.ac);
var r0 = J.adapter.readers.xtal.FSGOutputReader.getList(this.json, "transformation_matrix_ini_G0");
var t0 = J.adapter.readers.xtal.FSGOutputReader.getList(this.json, "origin_shift_ini_G0");
var m2g0 = this.readMatrix(r0, t0);
var abcm = JS.SymmetryOperation.getTransformABC(m2g0, false);
J.adapter.readers.xtal.FSGOutputReader.mput(m, "msgTransform", abcm);
this.asc.setCurrentModelInfo("unitcell_msg", abcm);
this.symmetry.setUnitCellFromParams(this.unitCellParams, true, 1.0E-4);
this.$spinFrame = this.calculateSpinFrame(this.readMatrix(J.adapter.readers.xtal.FSGOutputReader.getList(this.json, "transformation_matrix_spin_cartesian_lattice_G0"), null));
System.out.println("FSGOutput G0 spinFrame=" + this.$spinFrame);
this.addMoreUnitCellInfo("spinFrame=" + this.$spinFrame);
this.asc.setCurrentModelInfo("unitcell_spin", this.$spinFrame);
var spinLattice = fs.getLatticeCentering();
var lattice = JS.SymmetryOperation.getLatticeCenteringStrings(fs.getSymmetryOperations());
if (!lattice.isEmpty()) {
lattice.add(0, "x,y,z(u,v,w)");
J.adapter.readers.xtal.FSGOutputReader.mput(m, "G0_spinLattice", lattice);
}var abc = J.adapter.readers.xtal.FSGOutputReader.calculateChildTransform(spinLattice);
J.adapter.readers.xtal.FSGOutputReader.mput(m, "childTransform", abc);
System.out.println("FSGOutput G0_childTransform=" + abc);
var ops =  new JU.Lst();
var symops = fs.getSymmetryOperations();
for (var i = 0; i < this.firstTranslation; i++) {
ops.addLast(symops[i].getXyz(false));
}
if (spinList != null) {
J.adapter.readers.xtal.FSGOutputReader.mput(m, "G0_spinList", spinList);
var mapSpinToID =  new java.util.Hashtable();
var scifList = null;
var scifListTimeRev =  Clazz.newIntArray (spinList.size(), 0);
var newSpinFrame = (this.convertToABC ? "a,b,c" : this.$spinFrame);
scifList =  new JU.Lst();
var msf = null;
var msfInv = null;
if (!this.$spinFrame.equals(newSpinFrame)) {
msf = JS.SymmetryOperation.staticConvertOperation(this.$spinFrame, null, null);
msf.transpose();
msfInv = JU.M4.newM4(msf);
msfInv.invert();
}for (var i = 0, n = spinList.size(); i < n; i++) {
var fsgOp = spinList.get(i);
mapSpinToID.put(fsgOp, Integer.$valueOf(i));
var m4 = this.symmetry.convertTransform(fsgOp, null);
if (msf != null) {
m4.mul2(m4, msfInv);
m4.mul2(msf, m4);
}var s = JS.SymmetryOperation.getTransformUVW(m4);
System.out.println(s + "\t <- " + fsgOp);
scifList.addLast(s);
var timeReversal = Math.round(m4.determinant3());
scifListTimeRev[i] = timeReversal;
}
J.adapter.readers.xtal.FSGOutputReader.mput(m, "spinFrame", newSpinFrame);
J.adapter.readers.xtal.FSGOutputReader.mput(m, "SCIF_spinList", scifList);
J.adapter.readers.xtal.FSGOutputReader.mput(m, "SCIF_spinListTR", scifListTimeRev);
ops = this.setSCIFSpinLists(m, mapSpinToID, ops, this.firstTranslation, "G0_operationURefs");
this.setSCIFSpinLists(m, mapSpinToID, lattice, lattice.size(), "G0_spinLatticeURefs");
}J.adapter.readers.xtal.FSGOutputReader.mput(m, "G0_operations", ops);
J.adapter.readers.xtal.FSGOutputReader.mput(m, "G0_operationCount", Integer.$valueOf(ops.size()));
System.out.println("FSGOutput G0_operationCount(w/o translations)=" + ops.size());
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
J.adapter.readers.xtal.FSGOutputReader.mput(m, "exception", e.toString());
e.printStackTrace();
} else {
throw e;
}
}
return m;
}, "J.adapter.smarter.XtalSymmetry.FileSymmetry,JU.Lst");
Clazz.defineMethod(c$, "excludeAtoms", 
function(i0, bs, fs){
for (var i = bs.nextSetBit(i0 + 1); i >= 0; i = bs.nextSetBit(i + 1)) {
if (this.findSymop(i0, i, fs)) {
bs.clear(i);
}}
return bs.nextSetBit(i0 + 1);
}, "~N,JU.BS,J.adapter.smarter.XtalSymmetry.FileSymmetry");
Clazz.defineMethod(c$, "findSymop", 
function(i1, i2, fs){
var a = this.asc.atoms[i1];
var b = this.asc.atoms[i2];
if (a.elementNumber != b.elementNumber) return false;
this.p2.setP(b);
this.symmetry.unitize(this.p2);
this.symmetry.toCartesian(this.p2, true);
var ops = fs.getSymmetryOperations();
var nops = fs.getSpaceGroupOperationCount();
for (var i = 1; i < nops; i++) {
this.p1.setP(a);
ops[i].rotTrans(this.p1);
this.symmetry.unitize(this.p1);
this.symmetry.toCartesian(this.p1, true);
if (this.p1.distanceSquared(this.p2) < 0.01) {
return true;
}}
return false;
}, "~N,~N,J.adapter.smarter.XtalSymmetry.FileSymmetry");
Clazz.defineMethod(c$, "setSCIFSpinLists", 
function(m, mapSpinToID, ops, len, key){
var lst =  new JU.Lst();
var lstOpsIncluded =  new JU.Lst();
for (var i = 0, n = len; i < n; i++) {
var o = ops.get(i);
var pt = o.indexOf("(");
var uvw = o.substring(pt + 1, o.indexOf(')', pt + 1));
var ipt = mapSpinToID.get(uvw);
lst.addLast(ipt);
lstOpsIncluded.addLast(o);
}
var val =  Clazz.newIntArray (lst.size(), 0);
for (var i = val.length; --i >= 0; ) val[i] = lst.get(i).intValue();

m.put(key, val);
return lstOpsIncluded;
}, "java.util.Map,java.util.Map,JU.Lst,~N,~S");
c$.mput = Clazz.defineMethod(c$, "mput", 
function(m, key, val){
if (val != null) m.put(key, val);
}, "java.util.Map,~S,~O");
c$.calculateChildTransform = Clazz.defineMethod(c$, "calculateChildTransform", 
function(spinLattice){
if (spinLattice == null || spinLattice.isEmpty()) {
return "a,b,c";
}var minx = 1;
var miny = 1;
var minz = 1;
for (var i = spinLattice.size(); --i >= 0; ) {
var c = spinLattice.get(i);
if (c.x > 0 && c.x < minx) minx = c.x;
if (c.y > 0 && c.y < miny) miny = c.y;
if (c.z > 0 && c.z < minz) minz = c.z;
}
return (minx > 0 && minx < 1 ? "" + Math.round(1 / minx) : "") + "a," + (miny > 0 && miny < 1 ? "" + Math.round(1 / miny) : "") + "b," + (minz > 0 && minz < 1 ? "" + Math.round(1 / minz) : "") + "c";
}, "JU.Lst");
Clazz.defineMethod(c$, "calculateSpinFrame", 
function(m4){
if (m4 == null) {
m4 =  new JU.M4();
var a1 = JU.P3.new3(1, 0, 0);
var a2 = JU.P3.new3(0, 1, 0);
var a3 = JU.P3.new3(0, 0, 1);
this.symmetry.toFractional(a1, true);
this.symmetry.toFractional(a2, true);
this.symmetry.toFractional(a3, true);
var d = a1.length();
a1.normalize();
a2.scale(1 / d);
a3.scale(1 / d);
m4.setColumn4(0, a1.x, a1.y, a1.z, 0);
m4.setColumn4(1, a2.x, a2.y, a2.z, 0);
m4.setColumn4(2, a3.x, a3.y, a3.z, 0);
} else {
m4.invert();
}return JS.SymmetryOperation.getTransformABC(m4, false);
}, "JU.M4");
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
