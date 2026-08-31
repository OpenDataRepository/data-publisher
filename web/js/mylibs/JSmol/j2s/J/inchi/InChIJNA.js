Clazz.declarePackage("J.inchi");
Clazz.load(["J.inchi.InchiJmol", "java.util.Hashtable"], "J.inchi.InChIJNA", ["com.sun.jna.Native", "java.io.FileInputStream", "java.util.HashMap", "$.StringTokenizer", "org.iupac.InchiUtils"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.inchiModel = null;
this.map = null;
this.thisAtom = null;
this.thisBond = null;
this.thisStereo = null;
Clazz.instantialize(this, arguments);}, J.inchi, "InChIJNA", J.inchi.InchiJmol);
Clazz.prepareFields (c$, function(){
this.map =  new java.util.Hashtable();
});
Clazz.overrideMethod(c$, "getInchi", 
function(vwr, atoms, molData, options){
if ("version".equals(options)) return J.inchi.InChIJNA.getInternalInchiVersion();
try {
options = this.setParameters(options, molData, atoms, vwr);
if (options == null) return "";
var ops = J.inchi.InChIJNA.getOptions(options.toLowerCase());
var out = null;
var $in;
if (this.inputInChI) {
out = io.github.dan2097.jnainchi.JnaInchi.inchiToInchi(molData, ops);
} else if (!this.optionalFixedH) {
if (atoms == null) {
out = io.github.dan2097.jnainchi.JnaInchi.molToInchi(molData, ops);
} else {
$in = J.inchi.InChIJNA.newInchiStructureBS(vwr, atoms);
out = io.github.dan2097.jnainchi.JnaInchi.toInchi($in, J.inchi.InChIJNA.getOptions(this.doGetSmiles ? "fixedh" : options));
}}if (out != null) {
var msg = out.getMessage();
if (msg != null) System.err.println(msg);
this.inchi = out.getInchi();
}if (this.doGetSmiles || this.getInchiModel) {
this.inchiModel = io.github.dan2097.jnainchi.JnaInchi.getInchiInputFromInchi(this.inchi).getInchiInput();
return (this.doGetSmiles ? this.getSmiles(vwr, this.smilesOptions) : J.inchi.InChIJNA.toJSON(this.inchiModel));
}return (this.getKey ? io.github.dan2097.jnainchi.JnaInchi.inchiToInchiKey(this.inchi).getInchiKey() : this.inchi);
} catch (e) {
System.out.println(e);
if (e.getMessage() != null && e.getMessage().indexOf("ption") >= 0) System.out.println(e.getMessage() + ": " + options.toLowerCase() + "\n See https://www.inchi-trust.org/download/104/inchi-faq.pdf for valid options");
 else e.printStackTrace();
return "";
}
}, "JV.Viewer,JU.BS,~O,~S");
c$.getOptions = Clazz.defineMethod(c$, "getOptions", 
function(options){
var builder =  new io.github.dan2097.jnainchi.InchiOptions.InchiOptionsBuilder();
var t =  new java.util.StringTokenizer(options);
while (t.hasMoreElements()) {
var o = t.nextToken();
switch (o) {
case "smiles":
case "amide":
case "imine":
continue;
}
var f = io.github.dan2097.jnainchi.InchiFlag.getFlagFromName(o);
if (f == null) {
System.err.println("InChIJNA InChI option " + o + " not recognized -- ignored");
} else {
builder.withFlag([f]);
}}
return builder.build();
}, "~S");
c$.newInchiStructureBS = Clazz.defineMethod(c$, "newInchiStructureBS", 
function(vwr, bsAtoms){
var mol =  new io.github.dan2097.jnainchi.InchiInput();
var atoms =  new Array(bsAtoms.cardinality());
var map =  Clazz.newIntArray (bsAtoms.length(), 0);
var bsBonds = vwr.ms.getBondsForSelectedAtoms(bsAtoms, false);
for (var pt = 0, i = bsAtoms.nextSetBit(0); i >= 0; i = bsAtoms.nextSetBit(i + 1)) {
var a = vwr.ms.at[i];
var sym = a.getElementSymbolIso(false);
var iso = a.getIsotopeNumber();
if (a.getElementNumber() == 1) {
sym = "H";
}mol.addAtom(atoms[pt] =  new io.github.dan2097.jnainchi.InchiAtom(sym, a.x, a.y, a.z));
atoms[pt].setCharge(a.getFormalCharge());
if (iso > 0) atoms[pt].setIsotopicMass(iso);
map[i] = pt++;
}
var bonds = vwr.ms.bo;
for (var i = bsBonds.nextSetBit(0); i >= 0; i = bsBonds.nextSetBit(i + 1)) {
var bond = bonds[i];
if (bond == null) continue;
var order = J.inchi.InChIJNA.getOrder(Math.max(bond.isPartial() ? 1 : 0, bond.getCovalentOrder()));
if (order != null) {
var atom1 = bond.getAtomIndex1();
var atom2 = bond.getAtomIndex2();
var stereo = J.inchi.InChIJNA.getInChIStereo(bond.getBondType());
mol.addBond( new io.github.dan2097.jnainchi.InchiBond(atoms[map[atom1]], atoms[map[atom2]], order, stereo));
}}
return mol;
}, "JV.Viewer,JU.BS");
c$.getInChIStereo = Clazz.defineMethod(c$, "getInChIStereo", 
function(jmolOrder){
switch (jmolOrder) {
case 1041:
return io.github.dan2097.jnainchi.InchiBondStereo.SINGLE_1DOWN;
case 1025:
return io.github.dan2097.jnainchi.InchiBondStereo.SINGLE_1UP;
case 1057:
return io.github.dan2097.jnainchi.InchiBondStereo.SINGLE_1EITHER;
default:
return io.github.dan2097.jnainchi.InchiBondStereo.NONE;
}
}, "~N");
c$.getOrder = Clazz.defineMethod(c$, "getOrder", 
function(jmolOrder){
switch (jmolOrder) {
case 1057:
case 1041:
case 1025:
case 1:
return io.github.dan2097.jnainchi.InchiBondType.SINGLE;
case 2:
return io.github.dan2097.jnainchi.InchiBondType.DOUBLE;
case 3:
return io.github.dan2097.jnainchi.InchiBondType.TRIPLE;
default:
return null;
}
}, "~N");
c$.toJSON = Clazz.defineMethod(c$, "toJSON", 
function(mol){
var na = J.inchi.InChIJNA.sizeof(mol.getAtoms());
var nb = J.inchi.InChIJNA.sizeof(mol.getBonds());
var ns = J.inchi.InChIJNA.sizeof(mol.getStereos());
var mapAtoms =  new java.util.HashMap();
var haveXYZ = false;
for (var i = 0; i < na; i++) {
var a = mol.getAtom(i);
if (a.getX() != 0 || a.getY() != 0 || a.getZ() != 0) {
haveXYZ = true;
break;
}}
var s = "{";
s += "\n\"atomCount\":" + na + ",\n";
s += "\"atoms\":[\n";
for (var i = 0; i < na; i++) {
var a = mol.getAtom(i);
mapAtoms.put(a, Integer.$valueOf(i));
if (i > 0) s += ",\n";
s += "{";
s += J.inchi.InChIJNA.toJSONInt("index", (Integer.$valueOf(i)).intValue(), "");
s += J.inchi.InChIJNA.toJSONNotNone("elname", a.getElName(), ",");
if (haveXYZ) {
s += J.inchi.InChIJNA.toJSONDouble("x", a.getX(), ",");
s += J.inchi.InChIJNA.toJSONDouble("y", a.getY(), ",");
s += J.inchi.InChIJNA.toJSONDouble("z", a.getZ(), ",");
}s += J.inchi.InChIJNA.toJSONNonZero("isotopeMass", a.getIsotopicMass(), ",");
s += J.inchi.InChIJNA.toJSONNonZero("charge", a.getCharge(), ",");
s += J.inchi.InChIJNA.toJSONNotNone("radical", a.getRadical(), ",");
if (a.getImplicitHydrogen() > 0) s += J.inchi.InChIJNA.toJSONNonZero("implicitH", a.getImplicitHydrogen(), ",");
s += J.inchi.InChIJNA.toJSONNonZero("implicitDeuterium", a.getImplicitDeuterium(), ",");
s += J.inchi.InChIJNA.toJSONNonZero("implicitProtium", a.getImplicitProtium(), ",");
s += J.inchi.InChIJNA.toJSONNonZero("implicitTritium", a.getImplicitTritium(), ",");
s += "}";
}
s += "\n],";
s += "\n\"bondCount\":" + nb + ",\n";
s += "\n\"bonds\":[\n";
for (var i = 0; i < nb; i++) {
if (i > 0) s += ",\n";
s += "{";
var b = mol.getBond(i);
s += J.inchi.InChIJNA.toJSONInt("originAtom", mapAtoms.get(b.getStart()).intValue(), "");
s += J.inchi.InChIJNA.toJSONInt("targetAtom", mapAtoms.get(b.getEnd()).intValue(), ",");
var bt = J.inchi.InChIJNA.uc(b.getType());
if (!bt.equals("SINGLE")) s += J.inchi.InChIJNA.toJSONString("type", bt, ",");
s += J.inchi.InChIJNA.toJSONNotNone("stereo", J.inchi.InChIJNA.uc(b.getStereo()), ",");
s += "}";
}
s += "\n]";
if (ns > 0) {
s += ",\n\"stereoCount\":" + ns + ",\n";
s += "\"stereo\":[\n";
for (var i = 0; i < ns; i++) {
if (i > 0) s += ",\n";
s += "{";
var d = mol.getStereos().get(i);
s += J.inchi.InChIJNA.toJSONNotNone("type", J.inchi.InChIJNA.uc(d.getType()), "");
s += J.inchi.InChIJNA.toJSONNotNone("parity", J.inchi.InChIJNA.uc(d.getParity()), ",");
var an = d.getAtoms();
var nbs =  Clazz.newIntArray (an.length, 0);
for (var j = 0; j < an.length; j++) {
nbs[j] = mapAtoms.get(an[j]).intValue();
}
s += J.inchi.InChIJNA.toJSONArray("neighbors", nbs, ",");
var a = d.getCentralAtom();
if (a != null) s += J.inchi.InChIJNA.toJSONInt("centralAtom", mapAtoms.get(a).intValue(), ",");
s += "}";
}
s += "\n]";
}s += "}";
System.out.println(s);
return s;
}, "io.github.dan2097.jnainchi.InchiInput");
c$.sizeof = Clazz.defineMethod(c$, "sizeof", 
function(list){
return (list == null ? 0 : list.size());
}, "java.util.List");
c$.toJSONArray = Clazz.defineMethod(c$, "toJSONArray", 
function(key, val, term){
var s = term + "\"" + key + "\":[" + val[0];
for (var i = 1; i < val.length; i++) {
s += "," + val[i];
}
return s + "]";
}, "~S,~A,~S");
c$.toJSONNonZero = Clazz.defineMethod(c$, "toJSONNonZero", 
function(key, val, term){
return (val == 0 ? "" : J.inchi.InChIJNA.toJSONInt(key, val, term));
}, "~S,~N,~S");
c$.toJSONInt = Clazz.defineMethod(c$, "toJSONInt", 
function(key, val, term){
return term + "\"" + key + "\":" + val;
}, "~S,~N,~S");
c$.toJSONDouble = Clazz.defineMethod(c$, "toJSONDouble", 
function(key, val, term){
var s;
if (val == 0) {
s = "0";
} else {
s = "" + (val + (val > 0 ? 0.00000001 : -1.0E-8));
s = s.substring(0, s.indexOf(".") + 5);
var n = s.length;
while (s.charAt(--n) == '0') {
}
s = s.substring(0, n + 1);
}return term + "\"" + key + "\":" + s;
}, "~S,~N,~S");
c$.toJSONString = Clazz.defineMethod(c$, "toJSONString", 
function(key, val, term){
return term + "\"" + key + "\":\"" + val + "\"";
}, "~S,~S,~S");
c$.toJSONNotNone = Clazz.defineMethod(c$, "toJSONNotNone", 
function(key, val, term){
var s = val.toString();
return ("NONE".equals(s) ? "" : term + "\"" + key + "\":\"" + s + "\"");
}, "~S,~O,~S");
Clazz.overrideMethod(c$, "initializeModelForSmiles", 
function(){
for (var i = this.getNumAtoms(); --i >= 0; ) this.map.put(this.inchiModel.getAtom(i), Integer.$valueOf(i));

});
Clazz.overrideMethod(c$, "getNumAtoms", 
function(){
return J.inchi.InChIJNA.sizeof(this.inchiModel.getAtoms());
});
Clazz.overrideMethod(c$, "setAtom", 
function(i){
this.thisAtom = this.inchiModel.getAtom(i);
return this;
}, "~N");
Clazz.overrideMethod(c$, "getElementType", 
function(){
return this.thisAtom.getElName();
});
Clazz.overrideMethod(c$, "getX", 
function(){
return this.thisAtom.getX();
});
Clazz.overrideMethod(c$, "getY", 
function(){
return this.thisAtom.getY();
});
Clazz.overrideMethod(c$, "getZ", 
function(){
return this.thisAtom.getZ();
});
Clazz.overrideMethod(c$, "getCharge", 
function(){
return this.thisAtom.getCharge();
});
Clazz.overrideMethod(c$, "getIsotopicMass", 
function(){
return org.iupac.InchiUtils.getActualMass(this.getElementType(), this.thisAtom.getIsotopicMass());
});
Clazz.overrideMethod(c$, "getImplicitH", 
function(){
return this.thisAtom.getImplicitHydrogen();
});
Clazz.overrideMethod(c$, "getNumBonds", 
function(){
return J.inchi.InChIJNA.sizeof(this.inchiModel.getBonds());
});
Clazz.overrideMethod(c$, "setBond", 
function(i){
this.thisBond = this.inchiModel.getBond(i);
return this;
}, "~N");
Clazz.overrideMethod(c$, "getIndexOriginAtom", 
function(){
return this.map.get(this.thisBond.getStart()).intValue();
});
Clazz.overrideMethod(c$, "getIndexTargetAtom", 
function(){
return this.map.get(this.thisBond.getEnd()).intValue();
});
Clazz.overrideMethod(c$, "getInchiBondType", 
function(){
var type = this.thisBond.getType();
return type.name();
});
Clazz.overrideMethod(c$, "getNumStereo0D", 
function(){
return J.inchi.InChIJNA.sizeof(this.inchiModel.getStereos());
});
Clazz.overrideMethod(c$, "setStereo0D", 
function(i){
this.thisStereo = this.inchiModel.getStereos().get(i);
return this;
}, "~N");
Clazz.overrideMethod(c$, "getNeighbors", 
function(){
var an = this.thisStereo.getAtoms();
var n = an.length;
var a =  Clazz.newIntArray (n, 0);
for (var i = 0; i < n; i++) {
a[i] = this.map.get(an[i]).intValue();
}
return a;
});
Clazz.overrideMethod(c$, "getCenterAtom", 
function(){
var ca = this.thisStereo.getCentralAtom();
return (ca == null ? -1 : this.map.get(ca).intValue());
});
Clazz.overrideMethod(c$, "getStereoType", 
function(){
return J.inchi.InChIJNA.uc(this.thisStereo.getType());
});
Clazz.overrideMethod(c$, "getParity", 
function(){
return J.inchi.InChIJNA.uc(this.thisStereo.getParity());
});
c$.uc = Clazz.defineMethod(c$, "uc", 
function(o){
return o.toString().toUpperCase();
}, "~O");
c$.getInternalInchiVersion = Clazz.defineMethod(c$, "getInternalInchiVersion", 
function(){
if (J.inchi.InChIJNA.inchiVersionInternal == null) {
var f = io.github.dan2097.jnainchi.inchi.InchiLibrary.JNA_NATIVE_LIB.getFile();
J.inchi.InChIJNA.inchiVersionInternal = J.inchi.InChIJNA.extractInchiVersionInternal(f);
if (J.inchi.InChIJNA.inchiVersionInternal == null) {
try {
f = com.sun.jna.Native.extractFromResourcePath(io.github.dan2097.jnainchi.inchi.InchiLibrary.JNA_NATIVE_LIB.getName());
J.inchi.InChIJNA.inchiVersionInternal = J.inchi.InChIJNA.extractInchiVersionInternal(f);
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
} else {
throw e;
}
}
}if (J.inchi.InChIJNA.inchiVersionInternal == null) J.inchi.InChIJNA.inchiVersionInternal = "unknown";
}return J.inchi.InChIJNA.inchiVersionInternal;
});
c$.extractInchiVersionInternal = Clazz.defineMethod(c$, "extractInchiVersionInternal", 
function(f){
var s = null;
try {
var fis =  new java.io.FileInputStream(f);
try {
var b =  Clazz.newByteArray (f.length(), 0);
fis.read(b);
s =  String.instantialize(b);
var pt = s.indexOf("InChI version");
if (pt < 0) {
s = null;
} else {
s = s.substring(pt, s.indexOf('\0', pt));
}fis.close();
f.$delete();

}finally{/*res*/fis &&fis .close&&fis .close();}
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
} else {
throw e;
}
}
return s;
}, "java.io.File");
c$.inchiVersionInternal = null;
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
