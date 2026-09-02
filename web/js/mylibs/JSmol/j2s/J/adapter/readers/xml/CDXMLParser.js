Clazz.declarePackage("J.adapter.readers.xml");
Clazz.load(["java.util.HashMap", "JU.BS", "$.Lst"], "J.adapter.readers.xml.CDXMLParser", null, function(){
var c$ = Clazz.decorateAsClass(function(){
this.minX = 3.4028235E38;
this.minY = 3.4028235E38;
this.minZ = 3.4028235E38;
this.maxZ = -3.4028235E38;
this.maxY = -3.4028235E38;
this.maxX = -3.4028235E38;
this.idnext = 100000;
this.bsAtoms = null;
this.bsBonds = null;
this.atoms = null;
this.bonds = null;
this.bondIDMap = null;
this.bracketedGroups = null;
this.rdr = null;
this.mapCloned = null;
if (!Clazz.isClassDefined("J.adapter.readers.xml.CDXMLParser.CDNode")) {
J.adapter.readers.xml.CDXMLParser.$CDXMLParser$CDNode$ ();
}
if (!Clazz.isClassDefined("J.adapter.readers.xml.CDXMLParser.CDBond")) {
J.adapter.readers.xml.CDXMLParser.$CDXMLParser$CDBond$ ();
}
if (!Clazz.isClassDefined("J.adapter.readers.xml.CDXMLParser.BracketedGroup")) {
J.adapter.readers.xml.CDXMLParser.$CDXMLParser$BracketedGroup$ ();
}
this.fragments = null;
this.thisFragmentID = null;
this.thisNode = null;
this.thisAtom = null;
this.ignoreText = false;
this.nodes = null;
this.nostereo = null;
this.objectsByID = null;
this.textBuffer = null;
Clazz.instantialize(this, arguments);}, J.adapter.readers.xml, "CDXMLParser", null);
Clazz.prepareFields (c$, function(){
this.bsAtoms =  new JU.BS();
this.bsBonds =  new JU.BS();
this.atoms =  new JU.Lst();
this.bonds =  new JU.Lst();
this.bondIDMap =  new java.util.HashMap();
this.fragments =  new JU.Lst();
this.nodes =  new JU.Lst();
this.nostereo =  new JU.Lst();
this.objectsByID =  new java.util.HashMap();
});
Clazz.makeConstructor(c$, 
function(reader){
this.rdr = reader;
}, "J.adapter.readers.xml.CDXMLParser.CDXReaderI");
Clazz.defineMethod(c$, "processStartElement", 
function(localName, atts){
var id = atts.get("id");
switch (localName) {
case "n":
this.objectsByID.put(id, this.setNode(id, atts));
break;
case "b":
this.objectsByID.put(id, this.setBond(id, atts));
break;
case "t":
this.textBuffer = "";
break;
case "s":
this.rdr.setKeepChars(true);
break;
case "fragment":
this.objectsByID.put(id, this.setFragment(id, atts));
break;
case "objecttag":
switch (atts.get("name")) {
case "parameterizedBracketLabel":
case "bracketusage":
this.ignoreText = true;
break;
}
break;
case "bracketedgroup":
this.setBracketedGroup(id, atts);
break;
case "crossingbond":
var bg = (this.bracketedGroups == null || this.bracketedGroups.isEmpty() ? null : this.bracketedGroups.get(this.bracketedGroups.size() - 1));
if (bg != null && bg.repeatCount > 0) {
bg.addCrossing(atts.get("inneratomid"), atts.get("bondid"));
}break;
}
}, "~S,java.util.Map");
Clazz.defineMethod(c$, "nextID", 
function(){
return "" + (this.idnext++);
});
c$.getBondKey = Clazz.defineMethod(c$, "getBondKey", 
function(atomIndex1, atomIndex2){
return Math.min(atomIndex1, atomIndex2) + "_" + Math.max(atomIndex1, atomIndex2);
}, "~N,~N");
Clazz.defineMethod(c$, "getBond", 
function(a, b){
return this.bondIDMap.get(J.adapter.readers.xml.CDXMLParser.getBondKey(a.index, b.index));
}, "J.adapter.readers.xml.CDXMLParser.CDNode,J.adapter.readers.xml.CDXMLParser.CDNode");
Clazz.defineMethod(c$, "processEndElement", 
function(localName, chars){
switch (localName) {
case "fragment":
this.thisFragmentID = this.fragments.removeItemAt(this.fragments.size() - 1);
return;
case "objecttag":
this.ignoreText = false;
return;
case "n":
this.thisNode = (this.nodes.size() == 0 ? null : this.nodes.removeItemAt(this.nodes.size() - 1));
return;
case "bracketedgroup":
break;
case "s":
this.textBuffer += chars.toString();
break;
case "t":
if (this.ignoreText) {
} else if (this.thisNode == null) {
System.out.println("CDXReader unassigned text: " + this.textBuffer);
} else {
this.thisNode.text = this.textBuffer;
if (this.thisAtom.elementNumber == 0) {
System.err.println("XmlChemDrawReader: Problem with \"" + this.textBuffer + "\"");
}if (this.thisNode.warning != null) this.rdr.warn("Warning: " + this.textBuffer + " " + this.thisNode.warning);
}this.textBuffer = "";
break;
}
this.rdr.setKeepChars(false);
}, "~S,~S");
Clazz.defineMethod(c$, "getTokens", 
function(s){
return s.$plit("\\s");
}, "~S");
Clazz.defineMethod(c$, "split", 
function(s, p){
return s.$plit(p);
}, "~S,~S");
Clazz.defineMethod(c$, "parseInt", 
function(s){
try {
return Integer.parseInt(s);
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
return -2147483648;
} else {
throw e;
}
}
}, "~S");
Clazz.defineMethod(c$, "parseFloat", 
function(s){
try {
return Float.parseFloat(s);
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
return NaN;
} else {
throw e;
}
}
}, "~S");
Clazz.defineMethod(c$, "setNode", 
function(id, atts){
var nodeType = atts.get("nodetype");
if (this.thisNode != null) this.nodes.addLast(this.thisNode);
if ("_".equals(nodeType)) {
this.thisAtom = this.thisNode = null;
return null;
}this.thisAtom = this.thisNode = Clazz.innerTypeInstance(J.adapter.readers.xml.CDXMLParser.CDNode, this, null, this.atoms.size(), id, nodeType, this.thisFragmentID, this.thisNode);
this.addAtom(this.thisNode);
var w = atts.get("warning");
if (w != null) {
this.thisNode.warning = w.$replace("&apos;", "'");
this.thisNode.isValid = (w.indexOf("ChemDraw can't interpret") < 0);
}var element = atts.get("element");
var s = atts.get("genericnickname");
if (s != null) {
element = s;
}this.thisAtom.element = element;
this.thisAtom.elementNumber = Math.max(0, (!this.checkWarningOK(w) ? 0 : element == null ? 6 : this.parseInt(element)));
this.thisAtom.isotope = atts.get("isotope");
s = atts.get("charge");
if (s != null) {
this.thisAtom.formalCharge = this.parseInt(s);
}this.rdr.handleCoordinates(atts);
s = atts.get("attachments");
if (s != null) {
this.thisNode.setMultipleAttachments(this.split(s.trim(), " "));
}s = atts.get("bondordering");
if (s != null) {
this.thisNode.setBondOrdering(this.split(s.trim(), " "));
}return this.thisNode;
}, "~S,java.util.Map");
Clazz.defineMethod(c$, "checkWarningOK", 
function(warning){
return (warning == null || warning.indexOf("valence") >= 0 || warning.indexOf("very close") >= 0 || warning.indexOf("two identical colinear bonds") >= 0);
}, "~S");
Clazz.defineMethod(c$, "setFragment", 
function(id, atts){
this.fragments.addLast(this.thisFragmentID = id);
var fragmentNode = (this.thisNode == null || !this.thisNode.isFragment ? null : this.thisNode);
if (fragmentNode != null) {
fragmentNode.setInnerFragmentID(id);
}var s = atts.get("connectionorder");
if (s != null) {
this.thisNode.setConnectionOrder(s.trim().$plit(" "));
}return fragmentNode;
}, "~S,java.util.Map");
Clazz.defineMethod(c$, "setBond", 
function(id, atts){
var atom1 = atts.get("b");
var atom2 = atts.get("e");
var a = atts.get("beginattach");
var beginAttach = (a == null ? 0 : this.parseInt(a));
a = atts.get("endattach");
var endAttach = (a == null ? 0 : this.parseInt(a));
var s = atts.get("order");
var disp = atts.get("display");
var disp2 = atts.get("display2");
var order = this.rdr.getBondOrder("null");
var invertEnds = false;
if (disp == null) {
if (s == null) {
order = 1;
} else if (s.equals("1.5")) {
order = this.rdr.getBondOrder("delocalized");
} else {
if (s.indexOf(".") > 0 && !"Dash".equals(disp2)) {
s = s.substring(0, s.indexOf("."));
}order = this.rdr.getBondOrder(s);
}} else if (disp.equals("WedgeBegin")) {
order = this.rdr.getBondOrder("up");
} else if (disp.equals("Hash") || disp.equals("WedgedHashBegin")) {
order = this.rdr.getBondOrder("down");
} else if (disp.equals("WedgeEnd")) {
invertEnds = true;
order = this.rdr.getBondOrder("up");
} else if (disp.equals("WedgedHashEnd")) {
invertEnds = true;
order = this.rdr.getBondOrder("down");
} else if (disp.equals("Bold")) {
order = this.rdr.getBondOrder("single");
} else if (disp.equals("Wavy")) {
order = this.rdr.getBondOrder("either");
}if (order == this.rdr.getBondOrder("null")) {
System.err.println("XmlChemDrawReader ignoring bond type " + s);
return null;
}var b = (invertEnds ? Clazz.innerTypeInstance(J.adapter.readers.xml.CDXMLParser.CDBond, this, null, id, atom2, atom1, order) : Clazz.innerTypeInstance(J.adapter.readers.xml.CDXMLParser.CDBond, this, null, id, atom1, atom2, order));
var node1 = this.getAtom(b.atomIndex1);
var node2 = this.getAtom(b.atomIndex2);
if (order == this.rdr.getBondOrder("either")) {
if (!this.nostereo.contains(node1)) this.nostereo.addLast(node1);
if (!this.nostereo.contains(node2)) this.nostereo.addLast(node2);
}if (node1.hasMultipleAttachments) {
node1.attachedAtom = node2;
return b;
} else if (node2.hasMultipleAttachments) {
node2.attachedAtom = node1;
return b;
}if (node1.isFragment && beginAttach == 0) beginAttach = 1;
if (node2.isFragment && endAttach == 0) endAttach = 1;
if (beginAttach > 0) {
(invertEnds ? node2 : node1).addAttachedAtom(b, beginAttach);
}if (endAttach > 0) {
(invertEnds ? node1 : node2).addAttachedAtom(b, endAttach);
}if (node1.isExternalPt) {
node1.setInternalAtom(node2);
}if (node2.isExternalPt) {
node2.setInternalAtom(node1);
}this.addBond(b);
return b;
}, "~S,java.util.Map");
Clazz.defineMethod(c$, "setBracketedGroup", 
function(id, atts){
var usage = atts.get("bracketusage");
if (this.bracketedGroups == null) this.bracketedGroups =  new JU.Lst();
if ("MultipleGroup".equals(usage)) {
var ids = this.getTokens(atts.get("bracketedobjectids"));
var repeatCount = this.parseInt(atts.get("repeatcount"));
this.bracketedGroups.addLast(Clazz.innerTypeInstance(J.adapter.readers.xml.CDXMLParser.BracketedGroup, this, null, id, ids, repeatCount));
}}, "~S,java.util.Map");
Clazz.defineMethod(c$, "setAtom", 
function(key, atts){
var xyz = atts.get(key);
var tokens = this.getTokens(xyz);
var x = this.parseFloat(tokens[0]);
var y = -this.parseFloat(tokens[1]);
var z = (key === "xyz" ? this.parseFloat(tokens[2]) : 0);
if (x < this.minX) this.minX = x;
if (x > this.maxX) this.maxX = x;
if (y < this.minY) this.minY = y;
if (y > this.maxY) this.maxY = y;
if (z < this.minZ) this.minZ = z;
if (z > this.maxZ) this.maxZ = z;
this.thisAtom.set(x, y, z);
}, "~S,java.util.Map");
Clazz.defineMethod(c$, "fixInvalidAtoms", 
function(){
for (var i = this.getAtomCount(); --i >= 0; ) {
var a = this.getAtom(i);
a.intID = -2147483648;
if (a.isFragment || a.isExternalPt || !a.isConnected && (!a.isValid || a.elementNumber < 10)) {
this.bsAtoms.clear(a.index);
}}
this.reserializeAtoms();
this.bsBonds.clearAll();
for (var i = this.getBondCount(); --i >= 0; ) {
var b = this.getBond(i);
if (b.isValid()) {
this.bsBonds.set(i);
} else {
}}
});
Clazz.defineMethod(c$, "reserializeAtoms", 
function(){
for (var p = 0, i = this.bsAtoms.nextSetBit(0); i >= 0; i = this.bsAtoms.nextSetBit(i + 1)) {
this.getAtom(i).intID = ++p;
}
});
Clazz.defineMethod(c$, "reindexAtomsAndBonds", 
function(){
this.reserializeAtoms();
for (var p = 0, i = this.bsAtoms.nextSetBit(0); i >= 0; i = this.bsAtoms.nextSetBit(i + 1)) {
this.getAtom(i).index = p++;
}
for (var i = this.bsBonds.nextSetBit(0); i >= 0; i = this.bsBonds.nextSetBit(i + 1)) {
var b = this.getBond(i);
b.atomIndex1 = b.atom1.index;
b.atomIndex2 = b.atom2.index;
}
});
Clazz.defineMethod(c$, "fixConnections", 
function(){
for (var i = this.getAtomCount(); --i >= 0; ) {
var a = this.getAtom(i);
if (a.isFragment || a.hasMultipleAttachments) a.fixAttachments();
}
for (var i = 0, n = this.getBondCount(); i < n; i++) {
var b = this.getBond(i);
if (b == null) {
continue;
}var a1 = b.atom1;
var a2 = b.atom2;
a1.isConnected = true;
a2.isConnected = true;
if (this.nostereo.contains(a1) != this.nostereo.contains(a2)) {
b.order = 1;
}}
});
Clazz.defineMethod(c$, "fixBracketedGroups", 
function(){
if (this.bracketedGroups == null) return;
for (var i = this.bracketedGroups.size(); --i >= 0; ) {
this.bracketedGroups.removeItemAt(i).process();
}
});
Clazz.defineMethod(c$, "dumpGraph", 
function(){
for (var i = 0, n = this.getAtomCount(); i < n; i++) {
var a = this.getAtom(i);
System.out.println("CDXMLP " + i + " id=" + a.id + a.bsConnections + " cid=" + a.clonedIndex + " fa=" + a.bsFragmentAtoms + " xp=" + a.isExternalPt + " ifd=" + a.innerFragmentID + " ofd= " + a.outerFragmentID);
}
for (var i = 0, n = this.getBondCount(); i < n; i++) {
var b = this.getBond(i);
System.out.println("CDXMLP bond " + i + " " + b.atomIndex1 + " " + b.atomIndex2 + b);
}
System.out.println(this.bondIDMap);
return;
});
Clazz.defineMethod(c$, "centerAndScale", 
function(){
if (this.minX > this.maxX) return;
var sum = 0;
var n = 0;
var lenH = 1;
for (var i = this.getBondCount(); --i >= 0; ) {
var b = this.getBond(i);
var a1 = b.atom1;
var a2 = b.atom2;
var d = a1.distance(a2);
if (a1.elementNumber > 1 && a2.elementNumber > 1) {
sum += d;
n++;
} else {
lenH = d;
}}
var f = (sum > 0 ? 1.45 * n / sum : lenH > 0 ? 1 / lenH : 1);
if (f > 0.5) f = 1;
var cx = (this.maxX + this.minX) / 2;
var cy = (this.maxY + this.minY) / 2;
var cz = (this.maxZ + this.minZ) / 2;
for (var i = this.getAtomCount(); --i >= 0; ) {
var a = this.getAtom(i);
a.x = (a.x - cx) * f;
a.y = (a.y - cy) * f;
a.z = (a.z - cz) * f;
}
});
Clazz.defineMethod(c$, "getAtom", 
function(i){
return this.atoms.get(i);
}, "~N");
Clazz.defineMethod(c$, "addAtom", 
function(atom){
this.atoms.addLast(atom);
this.bsAtoms.set(atom.index);
return atom;
}, "J.adapter.readers.xml.CDXMLParser.CDNode");
Clazz.defineMethod(c$, "getAtomCount", 
function(){
return this.atoms.size();
});
Clazz.defineMethod(c$, "addBond", 
function(b){
this.bsBonds.set(b.index = this.getBondCount());
this.bonds.addLast(b);
return b;
}, "J.adapter.readers.xml.CDXMLParser.CDBond");
Clazz.defineMethod(c$, "getBond", 
function(i){
return this.bonds.get(i);
}, "~N");
Clazz.defineMethod(c$, "getBondCount", 
function(){
return this.bonds.size();
});
Clazz.defineMethod(c$, "finalizeParsing", 
function(){
this.fixConnections();
this.fixInvalidAtoms();
this.fixBracketedGroups();
this.centerAndScale();
this.reindexAtomsAndBonds();
});
c$.$CDXMLParser$CDNode$ = function(){
/*if4*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
Clazz.prepareCallback(this, arguments);
this.index = 0;
this.id = null;
this.intID = 0;
this.isotope = null;
this.element = null;
this.elementNumber = 0;
this.x = 0;
this.y = 0;
this.z = 0;
this.formalCharge = 0;
this.nodeType = null;
this.warning = null;
this.isValid = true;
this.isConnected = false;
this.isExternalPt = false;
this.isFragment = false;
this.outerFragmentID = null;
this.innerFragmentID = null;
this.text = null;
this.parentNode = null;
this.orderedConnectionBonds = null;
this.internalAtom = null;
this.orderedExternalPoints = null;
this.attachments = null;
this.bondOrdering = null;
this.connectionOrder = null;
this.hasMultipleAttachments = false;
this.attachedAtom = null;
this.bsConnections = null;
this.bsFragmentAtoms = null;
this.clonedIndex = -1;
Clazz.instantialize(this, arguments);}, J.adapter.readers.xml.CDXMLParser, "CDNode", null, Cloneable);
Clazz.makeConstructor(c$, 
function(index, id, nodeType, fragmentID, parent){
if (id == null) return;
this.id = id;
this.index = index;
this.outerFragmentID = fragmentID;
this.intID = Integer.parseInt(id);
this.nodeType = nodeType;
this.parentNode = parent;
this.bsConnections =  new JU.BS();
this.isFragment = "Fragment".equals(nodeType) || "Nickname".equals(nodeType);
this.isExternalPt = "ExternalConnectionPoint".equals(nodeType);
if (this.isFragment) {
this.bsFragmentAtoms =  new JU.BS();
} else if (parent != null && !this.isExternalPt) {
if (parent.bsFragmentAtoms == null) {
parent.isFragment = true;
parent.bsFragmentAtoms =  new JU.BS();
}parent.bsFragmentAtoms.set(index);
}}, "~N,~S,~S,~S,J.adapter.readers.xml.CDXMLParser.CDNode");
Clazz.defineMethod(c$, "set", 
function(x, y, z){
this.x = x;
this.y = y;
this.z = z;
}, "~N,~N,~N");
Clazz.defineMethod(c$, "setInnerFragmentID", 
function(id){
this.innerFragmentID = id;
}, "~S");
Clazz.defineMethod(c$, "setBondOrdering", 
function(bondOrdering){
this.bondOrdering = bondOrdering;
}, "~A");
Clazz.defineMethod(c$, "setConnectionOrder", 
function(connectionOrder){
this.connectionOrder = connectionOrder;
}, "~A");
Clazz.defineMethod(c$, "setMultipleAttachments", 
function(attachments){
this.attachments = attachments;
this.hasMultipleAttachments = true;
}, "~A");
Clazz.defineMethod(c$, "addExternalPoint", 
function(externalPoint){
if (this.orderedExternalPoints == null) this.orderedExternalPoints =  new JU.Lst();
var i = this.orderedExternalPoints.size();
while (--i >= 0 && this.orderedExternalPoints.get(i).intID >= externalPoint.internalAtom.intID) {
}
this.orderedExternalPoints.add(++i, externalPoint);
}, "J.adapter.readers.xml.CDXMLParser.CDNode");
Clazz.defineMethod(c$, "setInternalAtom", 
function(a){
this.internalAtom = a;
if (this.parentNode != null) {
this.parentNode.addExternalPoint(this);
}}, "J.adapter.readers.xml.CDXMLParser.CDNode");
Clazz.defineMethod(c$, "addAttachedAtom", 
function(bond, pt){
if (this.orderedConnectionBonds == null) this.orderedConnectionBonds =  new JU.Lst();
var i = this.orderedConnectionBonds.size();
while (--i >= 0 && (this.orderedConnectionBonds.get(i)[0]).intValue() > pt) {
}
this.orderedConnectionBonds.add(++i,  Clazz.newArray(-1, [Integer.$valueOf(pt), bond]));
}, "J.adapter.readers.xml.CDXMLParser.CDBond,~N");
Clazz.defineMethod(c$, "fixAttachments", 
function(){
if (this.hasMultipleAttachments && this.attachedAtom != null) {
var order = this.b$["J.adapter.readers.xml.CDXMLParser"].rdr.getBondOrder("partial");
var a1 = this.attachedAtom;
for (var i = this.attachments.length; --i >= 0; ) {
var a = this.b$["J.adapter.readers.xml.CDXMLParser"].objectsByID.get(this.attachments[i]);
if (a != null) {
this.b$["J.adapter.readers.xml.CDXMLParser"].addBond(Clazz.innerTypeInstance(J.adapter.readers.xml.CDXMLParser.CDBond, this, null, null, a1.id, a.id, order));
}}
}if (this.orderedExternalPoints == null || this.text == null) return;
var n = this.orderedExternalPoints.size();
if (n != this.orderedConnectionBonds.size()) {
System.err.println("XmlCdxReader cannot fix attachments for fragment " + this.text);
return;
}if (this.bondOrdering == null) {
this.bondOrdering =  new Array(n);
for (var i = 0; i < n; i++) {
this.bondOrdering[i] = (this.orderedConnectionBonds.get(i)[1]).id;
}
}if (this.connectionOrder == null) {
this.connectionOrder =  new Array(n);
for (var i = 0; i < n; i++) {
this.connectionOrder[i] = this.orderedExternalPoints.get(i).id;
}
}for (var i = 0; i < n; i++) {
var b = this.b$["J.adapter.readers.xml.CDXMLParser"].objectsByID.get(this.bondOrdering[i]);
var a = (this.b$["J.adapter.readers.xml.CDXMLParser"].objectsByID.get(this.connectionOrder[i])).internalAtom;
this.updateExternalBond(b, a);
}
});
Clazz.defineMethod(c$, "updateExternalBond", 
function(bond2f, intAtom){
this.b$["J.adapter.readers.xml.CDXMLParser"].bsBonds.set(bond2f.index);
bond2f.disconnect();
if (bond2f.atomIndex2 == this.index) {
bond2f.connect(bond2f.atom1, intAtom);
} else if (bond2f.atomIndex1 == this.index) {
bond2f.connect(intAtom, bond2f.atom2);
} else {
System.err.println("CDXMLParser attachment failed! " + intAtom + " " + bond2f);
}}, "J.adapter.readers.xml.CDXMLParser.CDBond,J.adapter.readers.xml.CDXMLParser.CDNode");
Clazz.defineMethod(c$, "clone", 
function(){
var a;
try {
a = Clazz.superCall(this, J.adapter.readers.xml.CDXMLParser.CDNode, "clone", []);
a.index = this.b$["J.adapter.readers.xml.CDXMLParser"].atoms.size();
a.id = this.b$["J.adapter.readers.xml.CDXMLParser"].nextID();
this.b$["J.adapter.readers.xml.CDXMLParser"].mapCloned.put(this.id, a);
a.clonedIndex = this.index;
a.bsConnections =  new JU.BS();
this.b$["J.adapter.readers.xml.CDXMLParser"].objectsByID.put(a.id, a);
this.b$["J.adapter.readers.xml.CDXMLParser"].addAtom(a);
return a;
} catch (e) {
if (Clazz.exceptionOf(e,"CloneNotSupportedException")){
return null;
} else {
throw e;
}
}
});
Clazz.overrideMethod(c$, "toString", 
function(){
return "[CDNode " + this.id + " " + this.elementNumber + " index=" + this.index + " ext=" + this.isExternalPt + " frag=" + this.isFragment + " " + " " + this.x + " " + this.y + "]";
});
Clazz.defineMethod(c$, "distance", 
function(a2){
var dx = (this.x - a2.x);
var dy = (this.y - a2.y);
return Math.sqrt(dx * dx + dy * dy);
}, "J.adapter.readers.xml.CDXMLParser.CDNode");
Clazz.defineMethod(c$, "addBracketedAtoms", 
function(bsBracketed){
if (this.isFragment) bsBracketed.or(this.bsFragmentAtoms);
 else if (!this.isExternalPt) bsBracketed.set(this.index);
}, "JU.BS");
/*eoif4*/})();
};
c$.$CDXMLParser$CDBond$ = function(){
/*if4*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
Clazz.prepareCallback(this, arguments);
this.atomIndex1 = 0;
this.atomIndex2 = 0;
this.order = 0;
this.id = null;
this.id1 = null;
this.id2 = null;
this.atom1 = null;
this.atom2 = null;
this.index = 0;
this.invalidated = false;
Clazz.instantialize(this, arguments);}, J.adapter.readers.xml.CDXMLParser, "CDBond", null, Cloneable);
Clazz.makeConstructor(c$, 
function(id, id1, id2, order){
if (id1 == null) return;
this.id = (id == null ? this.b$["J.adapter.readers.xml.CDXMLParser"].nextID() : id);
this.id1 = id1;
this.id2 = id2;
this.order = order;
this.atom1 = this.b$["J.adapter.readers.xml.CDXMLParser"].objectsByID.get(id1);
this.atom2 = this.b$["J.adapter.readers.xml.CDXMLParser"].objectsByID.get(id2);
this.atomIndex1 = this.atom1.index;
this.atomIndex2 = this.atom2.index;
this.atom1.bsConnections.set(this.atomIndex2);
this.atom2.bsConnections.set(this.atomIndex1);
this.b$["J.adapter.readers.xml.CDXMLParser"].bondIDMap.put(J.adapter.readers.xml.CDXMLParser.getBondKey(this.atomIndex1, this.atomIndex2), this);
}, "~S,~S,~S,~N");
Clazz.defineMethod(c$, "connect", 
function(atomA, atomB){
this.atom1 = atomA;
this.atomIndex1 = atomA.index;
this.atom2 = atomB;
this.atomIndex2 = atomB.index;
atomA.bsConnections.set(atomB.index);
atomB.bsConnections.set(atomA.index);
this.b$["J.adapter.readers.xml.CDXMLParser"].bondIDMap.put(J.adapter.readers.xml.CDXMLParser.getBondKey(this.atomIndex1, this.atomIndex2), this);
}, "J.adapter.readers.xml.CDXMLParser.CDNode,J.adapter.readers.xml.CDXMLParser.CDNode");
Clazz.defineMethod(c$, "disconnect", 
function(){
this.atom1.bsConnections.clear(this.atomIndex2);
this.atom2.bsConnections.clear(this.atomIndex1);
this.b$["J.adapter.readers.xml.CDXMLParser"].bondIDMap.remove(J.adapter.readers.xml.CDXMLParser.getBondKey(this.atomIndex1, this.atomIndex2));
});
Clazz.defineMethod(c$, "getOtherNode", 
function(a){
return this.b$["J.adapter.readers.xml.CDXMLParser"].getAtom(this.atomIndex1 == a.index ? this.atomIndex2 : this.atomIndex1);
}, "J.adapter.readers.xml.CDXMLParser.CDNode");
Clazz.defineMethod(c$, "invalidate", 
function(){
this.invalidated = true;
this.b$["J.adapter.readers.xml.CDXMLParser"].bsBonds.clear(this.index);
this.atomIndex1 = this.atomIndex2 = -1;
});
Clazz.defineMethod(c$, "isValid", 
function(){
return (!this.invalidated && this.atom1.intID >= 0 && this.atom2.intID >= 0);
});
Clazz.overrideMethod(c$, "toString", 
function(){
return "[CDBond index " + this.index + " id " + this.id + " v=" + this.isValid() + " id1=" + this.id1 + "/" + this.atom1.index + "/" + this.atom1.clonedIndex + " id2=" + this.id2 + "/" + this.atom2.index + "/" + this.atom2.clonedIndex + "]";
});
/*eoif4*/})();
};
c$.$CDXMLParser$BracketedGroup$ = function(){
/*if4*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
Clazz.prepareCallback(this, arguments);
this.id = null;
this.ids = null;
this.bondIDs = null;
this.innerAtomIDs = null;
this.repeatCount = 0;
this.pt = 0;
Clazz.instantialize(this, arguments);}, J.adapter.readers.xml.CDXMLParser, "BracketedGroup", null);
Clazz.prepareFields (c$, function(){
this.bondIDs =  new Array(2);
this.innerAtomIDs =  new Array(2);
});
Clazz.makeConstructor(c$, 
function(id, ids, repeatCount){
this.id = id;
this.ids = ids;
this.repeatCount = repeatCount;
}, "~S,~A,~N");
Clazz.defineMethod(c$, "addCrossing", 
function(innerAtomID, bondID){
if (this.pt == 2) {
System.err.println("BracketedGroup has more than two crossings");
return;
}this.bondIDs[this.pt] = bondID;
this.innerAtomIDs[this.pt] = innerAtomID;
this.pt++;
}, "~S,~S");
Clazz.defineMethod(c$, "process", 
function(){
if (this.pt != 2 || this.repeatCount < 2) return;
var b1 = this.b$["J.adapter.readers.xml.CDXMLParser"].objectsByID.get(this.bondIDs[1]);
var a1i = this.b$["J.adapter.readers.xml.CDXMLParser"].objectsByID.get(this.innerAtomIDs[1]);
var a2i = this.b$["J.adapter.readers.xml.CDXMLParser"].objectsByID.get(this.innerAtomIDs[0]);
var b2 = this.b$["J.adapter.readers.xml.CDXMLParser"].objectsByID.get(this.bondIDs[0]);
var a2o = b2.getOtherNode(a2i);
var bsTrailingAtoms =  new JU.BS();
this.buildBranch(a2i, a2o, null, null, bsTrailingAtoms, null, null);
var offset =  Clazz.newFloatArray(-1, [a2o.x - a1i.x, a2o.y - a1i.y, a2o.z - a1i.z]);
var bsBracketed =  new JU.BS();
for (var i = this.ids.length; --i >= 0; ) {
var node = this.b$["J.adapter.readers.xml.CDXMLParser"].objectsByID.get(this.ids[i]);
node.addBracketedAtoms(bsBracketed);
}
var a1 = a1i;
var a2iLast = a2i;
for (var i = 1; i < this.repeatCount; i++) {
var nAtoms = this.b$["J.adapter.readers.xml.CDXMLParser"].getAtomCount();
var newNodes = this.duplicateBracketAtoms(a1i, a2i, bsBracketed);
a1 = newNodes[0];
this.patch(a2iLast, b1, a1);
a2iLast = newNodes[1];
this.shiftAtoms(nAtoms, offset, i, null);
}
this.patch(a2iLast, b2, a2o);
this.shiftAtoms(0, offset, this.repeatCount - 1, bsTrailingAtoms);
b2.invalidate();
});
Clazz.defineMethod(c$, "shiftAtoms", 
function(firstAtom, d, multiplier, bs){
if (bs == null) {
for (var i = this.b$["J.adapter.readers.xml.CDXMLParser"].getAtomCount(); --i >= firstAtom; ) {
var a = this.b$["J.adapter.readers.xml.CDXMLParser"].getAtom(i);
a.x += d[0] * multiplier;
a.y += d[1] * multiplier;
a.z += d[2] * multiplier;
}
} else {
for (var i = bs.nextSetBit(0); i >= 0; i = bs.nextSetBit(i + 1)) {
var a = this.b$["J.adapter.readers.xml.CDXMLParser"].getAtom(i);
a.x += d[0] * multiplier;
a.y += d[1] * multiplier;
a.z += d[2] * multiplier;
}
}}, "~N,~A,~N,JU.BS");
Clazz.defineMethod(c$, "duplicateBracketAtoms", 
function(a1i, a2i, bsBracketed){
this.b$["J.adapter.readers.xml.CDXMLParser"].mapCloned =  new java.util.HashMap();
var aNew = a1i.clone();
var a12i =  Clazz.newArray(-1, [aNew, a1i === a2i ? aNew : null]);
var bsDone =  new JU.BS();
this.buildBranch(null, a1i, a2i, aNew, bsDone, bsBracketed, a12i);
return a12i;
}, "J.adapter.readers.xml.CDXMLParser.CDNode,J.adapter.readers.xml.CDXMLParser.CDNode,JU.BS");
Clazz.defineMethod(c$, "buildBranch", 
function(prev, aRoot, aEnd, a, bsDone, bsBracketed, a12i){
bsDone.set(aRoot.index);
var aNext = null;
for (var i = aRoot.bsConnections.nextSetBit(0); i >= 0; i = aRoot.bsConnections.nextSetBit(i + 1)) {
var aBranch = this.b$["J.adapter.readers.xml.CDXMLParser"].getAtom(i);
if (aBranch === prev) continue;
var isNew = !bsDone.get(i);
if (bsBracketed != null) {
if (!bsBracketed.get(i) || aBranch.isExternalPt) continue;
var bBranch = this.b$["J.adapter.readers.xml.CDXMLParser"].getBond(aRoot, aBranch);
aNext = (isNew ? aBranch.clone() : this.b$["J.adapter.readers.xml.CDXMLParser"].mapCloned.get(aBranch.id));
if (aBranch === aEnd && a12i[1] == null) {
a12i[1] = aNext;
}var b = (bBranch.atom1 === aBranch ? Clazz.innerTypeInstance(J.adapter.readers.xml.CDXMLParser.CDBond, this, null, null, aNext.id, a.id, bBranch.order) : Clazz.innerTypeInstance(J.adapter.readers.xml.CDXMLParser.CDBond, this, null, null, a.id, aNext.id, bBranch.order));
this.b$["J.adapter.readers.xml.CDXMLParser"].addBond(b);
}if (isNew) {
this.buildBranch(aRoot, aBranch, aEnd, aNext, bsDone, bsBracketed, a12i);
}}
}, "J.adapter.readers.xml.CDXMLParser.CDNode,J.adapter.readers.xml.CDXMLParser.CDNode,J.adapter.readers.xml.CDXMLParser.CDNode,J.adapter.readers.xml.CDXMLParser.CDNode,JU.BS,JU.BS,~A");
Clazz.defineMethod(c$, "patch", 
function(a1, b, a2){
var b1 = this.b$["J.adapter.readers.xml.CDXMLParser"].addBond(Clazz.innerTypeInstance(J.adapter.readers.xml.CDXMLParser.CDBond, this, null, null, a1.id, a2.id, b.order));
b1.disconnect();
b1.connect(a1, a2);
}, "J.adapter.readers.xml.CDXMLParser.CDNode,J.adapter.readers.xml.CDXMLParser.CDBond,J.adapter.readers.xml.CDXMLParser.CDNode");
/*eoif4*/})();
};
Clazz.declareInterface(J.adapter.readers.xml.CDXMLParser, "CDXReaderI");
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
