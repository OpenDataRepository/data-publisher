Clazz.declarePackage("JS");
Clazz.load(["JU.M4"], "JS.HallInfo", ["JU.P3i", "$.SB", "JS.SymmetryOperation", "JU.Logger"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.hallSymbol = null;
this.primitiveHallSymbol = null;
this.latticeCode = '\0';
this.latticeExtension = null;
this.$isCentrosymmetric = false;
this.rotationTerms = null;
this.nRotations = 0;
this.vector12ths = null;
this.vectorCode = null;
Clazz.instantialize(this, arguments);}, JS, "HallInfo", null);
Clazz.prepareFields (c$, function(){
this.rotationTerms =  new Array(16);
});
Clazz.makeConstructor(c$, 
function(hallSymbol){
this.init(hallSymbol);
}, "~S");
Clazz.defineMethod(c$, "getRotationCount", 
function(){
return this.nRotations;
});
Clazz.defineMethod(c$, "isGenerated", 
function(){
return this.nRotations > 0;
});
Clazz.defineMethod(c$, "getLatticeCode", 
function(){
return this.latticeCode;
});
Clazz.defineMethod(c$, "isCentrosymmetric", 
function(){
return this.$isCentrosymmetric;
});
Clazz.defineMethod(c$, "getHallSymbol", 
function(){
return this.hallSymbol;
});
Clazz.defineMethod(c$, "init", 
function(hallSymbol){
try {
var str = this.hallSymbol = hallSymbol.trim();
str = this.extractLatticeInfo(str);
if (JS.HallInfo.HallTranslation.getLatticeIndex(this.latticeCode) == 0) return;
this.latticeExtension = JS.HallInfo.HallTranslation.getLatticeExtension(this.latticeCode, this.$isCentrosymmetric);
str = this.extractVectorInfo(str) + this.latticeExtension;
if (JU.Logger.debugging) JU.Logger.debug("Hallinfo: " + hallSymbol + " " + str);
var prevOrder = 0;
var prevAxisType = '\u0000';
this.primitiveHallSymbol = "P";
while (str.length > 0 && this.nRotations < 16) {
str = this.extractRotationInfo(str, prevOrder, prevAxisType);
var r = this.rotationTerms[this.nRotations - 1];
prevOrder = r.order;
prevAxisType = r.axisType;
this.primitiveHallSymbol += " " + r.primitiveCode;
}
this.primitiveHallSymbol += this.vectorCode;
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
JU.Logger.error("Invalid Hall symbol " + e);
this.nRotations = 0;
} else {
throw e;
}
}
}, "~S");
Clazz.defineMethod(c$, "dumpInfo", 
function(){
var sb =  new JU.SB();
sb.append("\nHall symbol: ").append(this.hallSymbol).append("\nprimitive Hall symbol: ").append(this.primitiveHallSymbol).append("\nlattice type: ").append(this.getLatticeDesignation());
for (var i = 0; i < this.nRotations; i++) {
sb.append("\n\nrotation term ").appendI(i + 1).append(this.rotationTerms[i].dumpInfo(this.vectorCode));
}
return sb.toString();
});
Clazz.defineMethod(c$, "getLatticeDesignation", 
function(){
return JS.HallInfo.HallTranslation.getLatticeDesignation2(this.latticeCode, this.$isCentrosymmetric);
});
Clazz.defineMethod(c$, "extractLatticeInfo", 
function(name){
var i = name.indexOf(" ");
if (i < 0) return "";
var term = name.substring(0, i).toUpperCase();
this.latticeCode = term.charAt(0);
if (this.latticeCode == '-') {
this.$isCentrosymmetric = true;
this.latticeCode = term.charAt(1);
}return name.substring(i + 1).trim();
}, "~S");
Clazz.defineMethod(c$, "extractVectorInfo", 
function(name){
this.vector12ths =  new JU.P3i();
this.vectorCode = "";
var i = name.indexOf("(");
var j = name.indexOf(")", i);
if (i > 0 && j > i) {
var term = name.substring(i + 1, j);
this.vectorCode = " (" + term + ")";
name = name.substring(0, i).trim();
i = term.indexOf(" ");
if (i >= 0) {
this.vector12ths.x = Integer.parseInt(term.substring(0, i));
term = term.substring(i + 1).trim();
i = term.indexOf(" ");
if (i >= 0) {
this.vector12ths.y = Integer.parseInt(term.substring(0, i));
term = term.substring(i + 1).trim();
}}this.vector12ths.z = Integer.parseInt(term);
}return name;
}, "~S");
Clazz.defineMethod(c$, "extractRotationInfo", 
function(name, prevOrder, prevAxisType){
var i = name.indexOf(" ");
var code;
if (i >= 0) {
code = name.substring(0, i);
name = name.substring(i + 1).trim();
} else {
code = name;
name = "";
}this.rotationTerms[this.nRotations] =  new JS.HallInfo.HallRotationTerm(this, code, prevOrder, prevAxisType);
this.nRotations++;
return name;
}, "~S,~N,~S");
Clazz.overrideMethod(c$, "toString", 
function(){
return this.hallSymbol;
});
Clazz.defineMethod(c$, "generateAllOperators", 
function(sg){
var mat1 =  new JU.M4();
var operation =  new JU.M4();
var newOps =  new Array(7);
for (var i = 0; i < 7; i++) newOps[i] =  new JU.M4();

var nOps = sg.getMatrixOperationCount();
for (var i = 0; i < this.nRotations; i++) {
var rt = this.rotationTerms[i];
mat1.setM4(rt.seitzMatrix12ths);
var nRot = rt.order;
newOps[0].setIdentity();
for (var j = 1; j <= nRot; j++) {
var m = newOps[j];
m.mul2(mat1, newOps[0]);
newOps[0].setM4(m);
var nNew = 0;
for (var k = 0; k < nOps; k++) {
operation.mul2(m, sg.getMatrixOperation(k));
operation.m03 = (Clazz.floatToInt(operation.m03) + 12) % 12;
operation.m13 = (Clazz.floatToInt(operation.m13) + 12) % 12;
operation.m23 = (Clazz.floatToInt(operation.m23) + 12) % 12;
if (sg.addHallOperationCheckDuplicates(operation)) {
nNew++;
}}
nOps += nNew;
}
}
}, "JS.HallInfo.HallReceiver");
c$.getHallLatticeEquivalent = Clazz.defineMethod(c$, "getHallLatticeEquivalent", 
function(shellXLATTCode){
var latticeCode = JS.HallInfo.HallTranslation.getLatticeCode(shellXLATTCode);
var isCentrosymmetric = (shellXLATTCode > 0);
return (isCentrosymmetric ? "-" : "") + latticeCode + " 1";
}, "~N");
c$.getLatticeDesignation = Clazz.defineMethod(c$, "getLatticeDesignation", 
function(latt){
return JS.HallInfo.HallTranslation.getLatticeDesignation(latt);
}, "~N");
c$.getLatticeIndex = Clazz.defineMethod(c$, "getLatticeIndex", 
function(latticeCode){
return JS.HallInfo.HallTranslation.getLatticeIndex(latticeCode);
}, "~S");
c$.getLatticeIndexFromCode = Clazz.defineMethod(c$, "getLatticeIndexFromCode", 
function(latticeParameter){
return JS.HallInfo.getLatticeIndex(JS.HallInfo.HallTranslation.getLatticeCode(latticeParameter));
}, "~N");
Clazz.declareInterface(JS.HallInfo, "HallReceiver");
/*if3*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
this.rotCode = null;
this.seitzMatrix = null;
this.seitzMatrixInv = null;
Clazz.instantialize(this, arguments);}, JS.HallInfo, "HallRotation", null);
Clazz.prepareFields (c$, function(){
this.seitzMatrix =  new JU.M4();
this.seitzMatrixInv =  new JU.M4();
});
Clazz.makeConstructor(c$, 
function(code, matrixData){
this.rotCode = code;
var data =  Clazz.newFloatArray (16, 0);
var dataInv =  Clazz.newFloatArray (16, 0);
data[15] = dataInv[15] = 1;
for (var i = 0, ipt = 0; ipt < 11; i++) {
var value = 0;
switch ((matrixData.charAt(i)).charCodeAt(0)) {
case 32:
ipt++;
continue;
case 43:
case 49:
value = 1;
break;
case 45:
value = -1;
break;
}
data[ipt] = value;
dataInv[ipt] = -value;
ipt++;
}
this.seitzMatrix.setA(data);
this.seitzMatrixInv.setA(dataInv);
}, "~S,~S");
c$.lookup = Clazz.defineMethod(c$, "lookup", 
function(code){
for (var i = JS.HallInfo.HallRotation.getHallTerms().length; --i >= 0; ) if (JS.HallInfo.HallRotation.hallRotationTerms[i].rotCode.equals(code)) return JS.HallInfo.HallRotation.hallRotationTerms[i];

return null;
}, "~S");
c$.getHallTerms = Clazz.defineMethod(c$, "getHallTerms", 
function(){
return (JS.HallInfo.HallRotation.hallRotationTerms == null ? JS.HallInfo.HallRotation.hallRotationTerms =  Clazz.newArray(-1, [ new JS.HallInfo.HallRotation("1_", "+00 0+0 00+"),  new JS.HallInfo.HallRotation("2x", "+00 0-0 00-"),  new JS.HallInfo.HallRotation("2y", "-00 0+0 00-"),  new JS.HallInfo.HallRotation("2z", "-00 0-0 00+"),  new JS.HallInfo.HallRotation("2'", "0-0 -00 00-"),  new JS.HallInfo.HallRotation("2\"", "0+0 +00 00-"),  new JS.HallInfo.HallRotation("2x'", "-00 00- 0-0"),  new JS.HallInfo.HallRotation("2x\"", "-00 00+ 0+0"),  new JS.HallInfo.HallRotation("2y'", "00- 0-0 -00"),  new JS.HallInfo.HallRotation("2y\"", "00+ 0-0 +00"),  new JS.HallInfo.HallRotation("2z'", "0-0 -00 00-"),  new JS.HallInfo.HallRotation("2z\"", "0+0 +00 00-"),  new JS.HallInfo.HallRotation("3x", "+00 00- 0+-"),  new JS.HallInfo.HallRotation("3y", "-0+ 0+0 -00"),  new JS.HallInfo.HallRotation("3z", "0-0 +-0 00+"),  new JS.HallInfo.HallRotation("3*", "00+ +00 0+0"),  new JS.HallInfo.HallRotation("4x", "+00 00- 0+0"),  new JS.HallInfo.HallRotation("4y", "00+ 0+0 -00"),  new JS.HallInfo.HallRotation("4z", "0-0 +00 00+"),  new JS.HallInfo.HallRotation("6x", "+00 0+- 0+0"),  new JS.HallInfo.HallRotation("6y", "00+ 0+0 -0+"),  new JS.HallInfo.HallRotation("6z", "+-0 +00 00+")]) : JS.HallInfo.HallRotation.hallRotationTerms);
});
c$.hallRotationTerms = null;
/*eoif3*/})();
/*if3*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
this.translationCode = '\0';
this.rotationOrder = 0;
this.rotationShift12ths = 0;
this.vectorShift12ths = null;
Clazz.instantialize(this, arguments);}, JS.HallInfo, "HallTranslation", null);
Clazz.makeConstructor(c$, 
function(translationCode, params){
this.translationCode = translationCode;
if (params != null) {
if (params.z >= 0) {
this.vectorShift12ths = params;
return;
}this.rotationOrder = params.x;
this.rotationShift12ths = params.y;
}this.vectorShift12ths =  new JU.P3i();
}, "~S,JU.P3i");
c$.getLatticeIndex = Clazz.defineMethod(c$, "getLatticeIndex", 
function(latt){
for (var i = 1, ipt = 3; i <= JS.HallInfo.HallTranslation.nLatticeTypes; i++, ipt += 3) if (JS.HallInfo.HallTranslation.latticeTranslationData[ipt].charAt(0) == latt) return i;

return 0;
}, "~S");
c$.getLatticeCode = Clazz.defineMethod(c$, "getLatticeCode", 
function(latt){
if (latt < 0) latt = -latt;
return (latt == 0 ? '\0' : latt > JS.HallInfo.HallTranslation.nLatticeTypes ? JS.HallInfo.HallTranslation.getLatticeCode(JS.HallInfo.HallTranslation.getLatticeIndex(String.fromCharCode(latt))) : JS.HallInfo.HallTranslation.latticeTranslationData[latt * 3].charAt(0));
}, "~N");
c$.getLatticeDesignation = Clazz.defineMethod(c$, "getLatticeDesignation", 
function(latt){
var isCentrosymmetric = (latt > 0);
var str = (isCentrosymmetric ? "-" : "");
if (latt < 0) latt = -latt;
if (latt == 0 || latt > JS.HallInfo.HallTranslation.nLatticeTypes) return "";
return str + JS.HallInfo.HallTranslation.getLatticeCode(latt) + ": " + (isCentrosymmetric ? "centrosymmetric " : "") + JS.HallInfo.HallTranslation.latticeTranslationData[latt * 3 + 1];
}, "~N");
c$.getLatticeDesignation2 = Clazz.defineMethod(c$, "getLatticeDesignation2", 
function(latticeCode, isCentrosymmetric){
var latt = JS.HallInfo.HallTranslation.getLatticeIndex(latticeCode);
if (!isCentrosymmetric) latt = -latt;
return JS.HallInfo.HallTranslation.getLatticeDesignation(latt);
}, "~S,~B");
c$.getLatticeExtension = Clazz.defineMethod(c$, "getLatticeExtension", 
function(latt, isCentrosymmetric){
for (var i = 1, ipt = 3; i <= JS.HallInfo.HallTranslation.nLatticeTypes; i++, ipt += 3) if (JS.HallInfo.HallTranslation.latticeTranslationData[ipt].charAt(0) == latt) return JS.HallInfo.HallTranslation.latticeTranslationData[ipt + 2] + (isCentrosymmetric ? " -1" : "");

return "";
}, "~S,~B");
c$.getHallTerms = Clazz.defineMethod(c$, "getHallTerms", 
function(){
return (JS.HallInfo.HallTranslation.hallTranslationTerms == null ? JS.HallInfo.HallTranslation.hallTranslationTerms =  Clazz.newArray(-1, [ new JS.HallInfo.HallTranslation('a', JU.P3i.new3(6, 0, 0)),  new JS.HallInfo.HallTranslation('b', JU.P3i.new3(0, 6, 0)),  new JS.HallInfo.HallTranslation('c', JU.P3i.new3(0, 0, 6)),  new JS.HallInfo.HallTranslation('n', JU.P3i.new3(6, 6, 6)),  new JS.HallInfo.HallTranslation('u', JU.P3i.new3(3, 0, 0)),  new JS.HallInfo.HallTranslation('v', JU.P3i.new3(0, 3, 0)),  new JS.HallInfo.HallTranslation('w', JU.P3i.new3(0, 0, 3)),  new JS.HallInfo.HallTranslation('d', JU.P3i.new3(3, 3, 3)),  new JS.HallInfo.HallTranslation('1', JU.P3i.new3(2, 6, -1)),  new JS.HallInfo.HallTranslation('1', JU.P3i.new3(3, 4, -1)),  new JS.HallInfo.HallTranslation('2', JU.P3i.new3(3, 8, -1)),  new JS.HallInfo.HallTranslation('1', JU.P3i.new3(4, 3, -1)),  new JS.HallInfo.HallTranslation('3', JU.P3i.new3(4, 9, -1)),  new JS.HallInfo.HallTranslation('1', JU.P3i.new3(6, 2, -1)),  new JS.HallInfo.HallTranslation('2', JU.P3i.new3(6, 4, -1)),  new JS.HallInfo.HallTranslation('4', JU.P3i.new3(6, 8, -1)),  new JS.HallInfo.HallTranslation('5', JU.P3i.new3(6, 10, -1)),  new JS.HallInfo.HallTranslation('r', JU.P3i.new3(4, 8, 8)),  new JS.HallInfo.HallTranslation('s', JU.P3i.new3(8, 8, 4)),  new JS.HallInfo.HallTranslation('t', JU.P3i.new3(8, 4, 8))]) : JS.HallInfo.HallTranslation.hallTranslationTerms);
});
c$.getHallTranslation = Clazz.defineMethod(c$, "getHallTranslation", 
function(translationCode, order){
var ht = null;
for (var i = JS.HallInfo.HallTranslation.getHallTerms().length; --i >= 0; ) {
var h = JS.HallInfo.HallTranslation.hallTranslationTerms[i];
if (h.translationCode == translationCode) {
if (h.rotationOrder == 0 || h.rotationOrder == order) {
ht =  new JS.HallInfo.HallTranslation(translationCode, null);
ht.translationCode = translationCode;
ht.rotationShift12ths = h.rotationShift12ths;
ht.vectorShift12ths = h.vectorShift12ths;
return ht;
}}}
return ht;
}, "~S,~N");
c$.latticeTranslationData =  Clazz.newArray(-1, ["\0", "unknown", "", "P", "primitive", "", "I", "body-centered", " 1n", "R", "rhombohedral", " 1r 1r", "F", "face-centered", " 1ab 1bc 1ac", "A", "A-centered", " 1bc", "B", "B-centered", " 1ac", "C", "C-centered", " 1ab", "S", "rhombohedral(S)", " 1s 1s", "T", "rhombohedral(T)", " 1t 1t"]);
c$.nLatticeTypes = Clazz.doubleToInt(JS.HallInfo.HallTranslation.latticeTranslationData.length / 3) - 1;
c$.hallTranslationTerms = null;
/*eoif3*/})();
/*if3*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
this.primitiveCode = null;
this.seitzMatrix12ths = null;
this.order = 0;
this.axisType = '\0';
this.inputCode = null;
this.lookupCode = null;
this.translationString = null;
this.rotation = null;
this.translation = null;
this.isImproper = false;
this.diagonalReferenceAxis = '\0';
this.allPositive = true;
Clazz.instantialize(this, arguments);}, JS.HallInfo, "HallRotationTerm", null);
Clazz.prepareFields (c$, function(){
this.seitzMatrix12ths =  new JU.M4();
});
Clazz.makeConstructor(c$, 
function(hallInfo, code, prevOrder, prevAxisType){
this.inputCode = code;
code += "   ";
if (code.charAt(0) == '-') {
this.isImproper = true;
code = code.substring(1);
}this.primitiveCode = "";
this.order = (code.charAt(0)).charCodeAt(0) - 48;
this.diagonalReferenceAxis = '\0';
this.axisType = '\0';
var ptr = 2;
var c;
switch ((c = code.charAt(1)).charCodeAt(0)) {
case 120:
case 121:
case 122:
switch ((code.charAt(2)).charCodeAt(0)) {
case 39:
case 34:
this.diagonalReferenceAxis = c;
c = code.charAt(2);
ptr++;
}
case 42:
this.axisType = c;
break;
case 39:
case 34:
this.axisType = c;
switch ((code.charAt(2)).charCodeAt(0)) {
case 120:
case 121:
case 122:
this.diagonalReferenceAxis = code.charAt(2);
ptr++;
break;
default:
this.diagonalReferenceAxis = prevAxisType;
}
break;
default:
this.axisType = (this.order == 1 ? '_' : hallInfo.nRotations == 0 ? 'z' : hallInfo.nRotations == 2 ? '*' : prevOrder == 2 || prevOrder == 4 ? 'x' : '\'');
code = code.substring(0, 1) + this.axisType + code.substring(1);
}
this.primitiveCode += (this.axisType == '_' ? "1" : code.substring(0, 2));
if (this.diagonalReferenceAxis != '\0') {
code = code.substring(0, 1) + this.diagonalReferenceAxis + this.axisType + code.substring(ptr);
this.primitiveCode += this.diagonalReferenceAxis;
ptr = 3;
}this.lookupCode = code.substring(0, ptr);
this.rotation = JS.HallInfo.HallRotation.lookup(this.lookupCode);
if (this.rotation == null) {
JU.Logger.error("Rotation lookup could not find " + this.inputCode + " ? " + this.lookupCode);
return;
}this.translation =  new JS.HallInfo.HallTranslation('\0', null);
this.translationString = "";
var len = code.length;
for (var i = ptr; i < len; i++) {
var translationCode = code.charAt(i);
var t = JS.HallInfo.HallTranslation.getHallTranslation(translationCode, this.order);
if (t != null) {
this.translationString += "" + t.translationCode;
this.translation.rotationShift12ths += t.rotationShift12ths;
this.translation.vectorShift12ths.add(t.vectorShift12ths);
}}
this.primitiveCode = (this.isImproper ? "-" : "") + this.primitiveCode + this.translationString;
this.seitzMatrix12ths.setM4(this.isImproper ? this.rotation.seitzMatrixInv : this.rotation.seitzMatrix);
this.seitzMatrix12ths.m03 = this.translation.vectorShift12ths.x;
this.seitzMatrix12ths.m13 = this.translation.vectorShift12ths.y;
this.seitzMatrix12ths.m23 = this.translation.vectorShift12ths.z;
switch ((this.axisType).charCodeAt(0)) {
case 120:
this.seitzMatrix12ths.m03 += this.translation.rotationShift12ths;
break;
case 121:
this.seitzMatrix12ths.m13 += this.translation.rotationShift12ths;
break;
case 122:
this.seitzMatrix12ths.m23 += this.translation.rotationShift12ths;
break;
}
if (hallInfo.vectorCode.length > 0) {
var m1 = JU.M4.newM4(null);
var m2 = JU.M4.newM4(null);
var v = hallInfo.vector12ths;
m1.m03 = v.x;
m1.m13 = v.y;
m1.m23 = v.z;
m2.m03 = -v.x;
m2.m13 = -v.y;
m2.m23 = -v.z;
this.seitzMatrix12ths.mul2(m1, this.seitzMatrix12ths);
this.seitzMatrix12ths.mul(m2);
}if (JU.Logger.debugging) {
JU.Logger.debug("code = " + code + "; primitive code =" + this.primitiveCode + "\n Seitz Matrix(12ths):" + this.seitzMatrix12ths);
}}, "JS.HallInfo,~S,~N,~S");
Clazz.defineMethod(c$, "dumpInfo", 
function(vectorCode){
var sb =  new JU.SB();
sb.append("\ninput code: ").append(this.inputCode).append("; primitive code: ").append(this.primitiveCode).append("\norder: ").appendI(this.order).append(this.isImproper ? " (improper axis)" : "");
if (this.axisType != '_') {
sb.append("; axisType: ").appendC(this.axisType);
if (this.diagonalReferenceAxis != '\0') sb.appendC(this.diagonalReferenceAxis);
}if (this.translationString.length > 0) sb.append("; translation: ").append(this.translationString);
if (vectorCode.length > 0) sb.append("; vector offset: ").append(vectorCode);
if (this.rotation != null) sb.append("\noperator: ").append(this.getXYZ(this.allPositive)).append("\nSeitz matrix:\n").append(JS.SymmetryOperation.dumpSeitz(this.seitzMatrix12ths, false));
return sb.toString();
}, "~S");
Clazz.defineMethod(c$, "getXYZ", 
function(allPositive){
return JS.SymmetryOperation.getXYZFromMatrix(this.seitzMatrix12ths, true, allPositive, true);
}, "~B");
/*eoif3*/})();
});
;//5.0.1-v7 Mon Aug 17 10:07:10 MDT 2026
