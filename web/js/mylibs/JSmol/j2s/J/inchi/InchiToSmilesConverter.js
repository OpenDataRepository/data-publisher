Clazz.declarePackage("J.inchi");
Clazz.load(["JU.Lst"], "J.inchi.InchiToSmilesConverter", ["java.util.Hashtable", "JU.BS", "JS.SmilesAtom", "$.SmilesBond", "JU.BSUtil", "$.Logger"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.mapTet = null;
this.mapPlanar = null;
this.listSmiles = null;
this.provider = null;
Clazz.instantialize(this, arguments);}, J.inchi, "InchiToSmilesConverter", null);
Clazz.prepareFields (c$, function(){
this.listSmiles =  new JU.Lst();
});
Clazz.makeConstructor(c$, 
function(provider){
this.provider = provider;
provider.initializeModelForSmiles();
}, "org.iupac.InChIStructureProvider");
Clazz.defineMethod(c$, "getSmiles", 
function(vwr, smilesOptions){
var hackImine = (smilesOptions.indexOf("imine") >= 0);
var bsImplicitH = (smilesOptions.indexOf("amide") >= 0 ?  new JU.BS() : null);
var nAtoms = this.provider.getNumAtoms();
var nBonds = this.provider.getNumBonds();
var nh = 0;
for (var i = 0; i < nAtoms; i++) {
nh += this.provider.setAtom(i).getImplicitH();
}
var atoms =  new JU.Lst();
this.mapTet =  new java.util.Hashtable();
this.mapPlanar =  new java.util.Hashtable();
var nb = 0;
var na = 0;
for (var i = 0; i < nAtoms; i++) {
this.provider.setAtom(i);
var n = ((Clazz.isClassDefined("J.inchi.InchiToSmilesConverter$1") ? 0 : J.inchi.InchiToSmilesConverter.$InchiToSmilesConverter$1$ ()), Clazz.innerTypeInstance(J.inchi.InchiToSmilesConverter$1, this, null));
atoms.addLast(n);
n.set(this.provider.getX(), this.provider.getY(), this.provider.getZ());
n.setIndex(na++);
n.setCharge(this.provider.getCharge());
n.setSymbol(this.provider.getElementType());
var m = this.provider.getIsotopicMass();
if (m > 0) n.setAtomicMass(m);
nh = this.provider.getImplicitH();
if (nh > 0 && bsImplicitH != null) bsImplicitH.set(na - 1);
for (var j = 0; j < nh; j++) {
this.addH(atoms, n, nb++);
na++;
}
this.listSmiles.addLast(n);
}
for (var i = 0; i < nBonds; i++) {
this.provider.setBond(i);
var bt = J.inchi.InchiToSmilesConverter.getJmolBondType(this.provider.getInchiBondType());
var sa1 = this.listSmiles.get(this.provider.getIndexOriginAtom());
var sa2 = this.listSmiles.get(this.provider.getIndexTargetAtom());
var sb =  new JS.SmilesBond(sa1, sa2, bt, false);
sb.index = nb++;
}
nb = this.checkSpecial(atoms, nb, hackImine, bsImplicitH);
na = atoms.size();
var aatoms =  new Array(na);
atoms.toArray(aatoms);
for (var i = 0; i < na; i++) {
aatoms[i].setBondArray();
}
var iA = -1;
var iB = -1;
for (var i = this.provider.getNumStereo0D(); --i >= 0; ) {
this.provider.setStereo0D(i);
var neighbors = this.provider.getNeighbors();
if (neighbors.length != 4) continue;
var centerAtom = this.provider.getCenterAtom();
var i0 = this.listSmiles.get(neighbors[0]).getIndex();
var i1 = this.listSmiles.get(neighbors[1]).getIndex();
var i2 = this.listSmiles.get(neighbors[2]).getIndex();
var i3 = this.listSmiles.get(neighbors[3]).getIndex();
var isEven = (this.provider.getParity().equals("EVEN"));
var type = this.provider.getStereoType();
switch (type) {
case "ALLENE":
case "DOUBLEBOND":
iA = i1;
iB = i2;
i1 = J.inchi.InchiToSmilesConverter.getOtherEneAtom(aatoms, i1, i0);
i2 = J.inchi.InchiToSmilesConverter.getOtherEneAtom(aatoms, i2, i3);
break;
case "NONE":
continue;
case "TETRAHEDRAL":
break;
}
if (centerAtom == -1) {
this.setPlanarKey(i0, i3, iA, iB, Boolean.$valueOf(isEven));
this.setPlanarKey(i0, i2, iA, iB, Boolean.$valueOf(!isEven));
this.setPlanarKey(i1, i2, iA, iB, Boolean.$valueOf(isEven));
this.setPlanarKey(i1, i3, iA, iB, Boolean.$valueOf(!isEven));
this.setPlanarKey(i0, i1, iA, iB, Boolean.TRUE);
this.setPlanarKey(i2, i3, iA, iB, Boolean.TRUE);
} else {
var list =  Clazz.newIntArray(-1, [isEven ? i0 : i1, isEven ? i1 : i0, i2, i3]);
this.mapTet.put(J.inchi.InchiToSmilesConverter.orderList(list), list);
}}
try {
var m = vwr.getSmilesMatcher();
var smiles = m.getSmiles(aatoms, na, JU.BSUtil.newBitSet2(0, na), smilesOptions, 1);
JU.Logger.info("InchiToSmiles: " + smiles);
return smiles;
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
e.printStackTrace();
return "";
} else {
throw e;
}
}
}, "JV.Viewer,~S");
Clazz.defineMethod(c$, "setPlanarKey", 
function(i0, i3, iA, iB, v){
this.mapPlanar.put(J.inchi.InchiToSmilesConverter.getIntKey(i0, iA, i3), v);
this.mapPlanar.put(J.inchi.InchiToSmilesConverter.getIntKey(i0, iB, i3), v);
}, "~N,~N,~N,~N,Boolean");
Clazz.defineMethod(c$, "addH", 
function(atoms, n, nb){
var h =  new JS.SmilesAtom();
h.setIndex(atoms.size());
h.setSymbol("H");
atoms.addLast(h);
var sb =  new JS.SmilesBond(n, h, 1, false);
sb.index = nb;
return h;
}, "JU.Lst,JS.SmilesAtom,~N");
Clazz.defineMethod(c$, "checkSpecial", 
function(atoms, nb, hackImine, bsImplicitH){
for (var i = atoms.size(); --i >= 0; ) {
var a = atoms.get(i);
var val = a.getValence();
var nbonds = a.getCovalentBondCount();
var nbtot = a.getBondCount();
var ano = a.getElementNumber();
var formalCharge = a.getCharge();
var b1 = null;
var b2 = null;
switch (val * 10 + nbonds) {
case 32:
if (ano == 7) {
if (hackImine) {
a.setSymbol("C");
a.setAtomicMass(17);
var h = this.addH(atoms, a, nb++);
h.setAtomicMass(5);
} else if (bsImplicitH != null) {
var c = this.getOther(atoms, a, 2, 6);
if (c != null && c.getElementNumber() == 6) {
var o = this.getOther(atoms, c, 1, 8);
if (o != null && bsImplicitH.get(o.getIndex())) {
var h = this.getOther(atoms, o, 1, 1);
var nc = this.getBond(a, c);
var co = this.getBond(o, c);
var oh = h.getBond(0);
co.set2(2, false);
nc.set2(1, false);
oh.set2(4096, false);
var b =  new JS.SmilesBond(h, a, 1, false);
b.index = oh.index;
}}}}break;
case 53:
if (ano == 7 && formalCharge == 0) {
for (var j = 0; j < nbtot; j++) {
var b = a.getBond(j);
if (b.getCovalentOrder() == 2) {
if (b1 == null) {
b1 = b;
} else {
b2 = b;
break;
}}}
}break;
case 54:
break;
}
if (b2 != null) {
var a2 = b2.getOtherAtom(a);
a2.setCharge(-1);
a.setCharge(1);
b2.set2(1, false);
}}
return nb;
}, "JU.Lst,~N,~B,JU.BS");
Clazz.defineMethod(c$, "getBond", 
function(a, c){
for (var i = a.getBondCount(); --i >= 0; ) {
var b = a.getBond(i);
if (b.getOtherAtom(a) === c) return b;
}
return null;
}, "JS.SmilesAtom,JS.SmilesAtom");
Clazz.defineMethod(c$, "getOther", 
function(atoms, a, bondType, elemNo){
for (var i = a.getBondCount(); --i >= 0; ) {
var a2;
if (a.getBond(i).getCovalentOrder() == bondType && (a2 = atoms.get(a.getBondedAtomIndex(i))).getElementNumber() == elemNo) {
return a2;
}}
return null;
}, "JU.Lst,JS.SmilesAtom,~N,~N");
Clazz.defineMethod(c$, "isInchiOpposite", 
function(i1, i2, iA, iB){
var b = this.mapPlanar.get(J.inchi.InchiToSmilesConverter.getIntKey(i1, Math.max(iA, iB), i2));
return b;
}, "~N,~N,~N,~N");
Clazz.defineMethod(c$, "decodeInchiStereo", 
function(nodes){
var list =  Clazz.newIntArray(-1, [J.inchi.InchiToSmilesConverter.getNodeIndex(nodes[0]), J.inchi.InchiToSmilesConverter.getNodeIndex(nodes[1]), J.inchi.InchiToSmilesConverter.getNodeIndex(nodes[2]), J.inchi.InchiToSmilesConverter.getNodeIndex(nodes[3])]);
var list2 = this.mapTet.get(J.inchi.InchiToSmilesConverter.orderList(list));
return (list2 == null ? null : J.inchi.InchiToSmilesConverter.isPermutation(list, list2) ? "@@" : "@");
}, "~A");
c$.getNodeIndex = Clazz.defineMethod(c$, "getNodeIndex", 
function(node){
return (node == null ? -1 : node.getIndex());
}, "JU.SimpleNode");
c$.getIntKey = Clazz.defineMethod(c$, "getIntKey", 
function(i, iA, j){
var v = Integer.$valueOf((Math.min(i, j) << 24) + (iA << 12) + Math.max(i, j));
return v;
}, "~N,~N,~N");
c$.orderList = Clazz.defineMethod(c$, "orderList", 
function(list){
var bs =  new JU.BS();
for (var i = 0; i < list.length; i++) bs.set(list[i]);

return bs;
}, "~A");
c$.isPermutation = Clazz.defineMethod(c$, "isPermutation", 
function(list, list2){
var ok = true;
for (var i = 0; i < 3; i++) {
var l1 = list[i];
for (var j = i + 1; j < 4; j++) {
var l2 = list2[j];
if (l2 == l1) {
if (j != i) {
list2[j] = list2[i];
list2[i] = l2;
ok = !ok;
}}}
}
return ok;
}, "~A,~A");
c$.getOtherEneAtom = Clazz.defineMethod(c$, "getOtherEneAtom", 
function(atoms, i0, i1){
var a = atoms[i0];
for (var i = a.getBondCount(); --i >= 0; ) {
if (a.getBond(i).getBondType() == 1) {
var i2 = a.getBondedAtomIndex(i);
if (i2 != i1) {
return i2;
}}}
return -1;
}, "~A,~N,~N");
c$.getJmolBondType = Clazz.defineMethod(c$, "getJmolBondType", 
function(type){
switch (type) {
case "NONE":
return 0;
case "ALTERN":
return 515;
case "DOUBLE":
return 2;
case "TRIPLE":
return 3;
case "SINGLE":
default:
return 1;
}
}, "~S");
c$.$InchiToSmilesConverter$1$=function(){
/*if5*/;(function(){
var c$ = Clazz.declareAnonymous(J.inchi, "InchiToSmilesConverter$1", JS.SmilesAtom);
Clazz.overrideMethod(c$, "definesStereo", 
function(){
return true;
});
Clazz.overrideMethod(c$, "getStereoAtAt", 
function(nodes){
return this.b$["J.inchi.InchiToSmilesConverter"].decodeInchiStereo(nodes);
}, "~A");
Clazz.overrideMethod(c$, "isStereoOpposite", 
function(i2, iA, iB){
return this.b$["J.inchi.InchiToSmilesConverter"].isInchiOpposite(this.getIndex(), i2, iA, iB);
}, "~N,~N,~N");
/*eoif5*/})();
};
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
