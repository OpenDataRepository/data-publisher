Clazz.declarePackage("J.adapter.readers.pymol");
Clazz.load(["java.util.Hashtable", "JU.Lst"], "J.adapter.readers.pymol.PickleReader", ["java.util.Arrays", "JU.AU", "JU.Logger"], function(){
var c$ = Clazz.decorateAsClass(function(){
this.vwr = null;
this.binaryDoc = null;
this.stack = null;
this.marks = null;
this.build = null;
this.memo = null;
this.logging = false;
this.id = 0;
this.markCount = 0;
this.emptyListPt = 0;
this.thisSection = null;
this.inMovie = false;
this.inNames = false;
this.retrieveCount = 0;
this.writer = null;
this.dumpFile = null;
this.ipt = 0;
this.aTemp = null;
if (!Clazz.isClassDefined("J.adapter.readers.pymol.PickleReader.Mark")) {
J.adapter.readers.pymol.PickleReader.$PickleReader$Mark$ ();
}
Clazz.instantialize(this, arguments);}, J.adapter.readers.pymol, "PickleReader", null);
Clazz.prepareFields (c$, function(){
this.stack =  new JU.Lst();
this.marks =  new JU.Lst();
this.build =  new JU.Lst();
this.memo =  new java.util.Hashtable();
this.aTemp =  Clazz.newByteArray (16, 0);
});
Clazz.makeConstructor(c$, 
function(doc, vwr){
this.binaryDoc = doc;
this.vwr = vwr;
this.stack.ensureCapacity(1000);
try {
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
} else {
throw e;
}
}
}, "javajs.api.GenericBinaryDocument,JV.Viewer");
Clazz.defineMethod(c$, "log", 
function(s){
this.vwr.log(s + "\0");
}, "~S");
Clazz.defineMethod(c$, "getMap", 
function(logging){
this.logging = logging;
var b;
var i;
var d;
var o;
var a;
var map;
var mark;
var l;
this.ipt = 0;
var going = true;
while (going) {
b = this.binaryDoc.readByte();
this.ipt++;
switch (b) {
case 125:
this.push( new java.util.Hashtable());
break;
case 97:
o = this.pop();
if (this.writer != null) this.dump("append", o);
var lst = this.peek();
lst.addLast(o);
if (this.writer != null && lst.size() < 10) this.dump("appended to " + lst);
break;
case 101:
mark = this.getMark();
l = this.getObjects(mark);
if (this.inNames && this.markCount == 2) {
this.addStartlen(l, mark);
}(this.peek()).addAll(l);
break;
case 71:
d = this.binaryDoc.readDouble();
this.push(Double.$valueOf(d));
break;
case 74:
i = this.binaryDoc.readIntLE();
this.push(Integer.$valueOf(i));
break;
case 75:
i = this.binaryDoc.readByte() & 0xff;
this.push(Integer.$valueOf(i));
break;
case 77:
i = (this.binaryDoc.readByte() & 0xff | ((this.binaryDoc.readByte() & 0xff) << 8)) & 0xffff;
this.push(Integer.$valueOf(i));
break;
case 113:
i = this.binaryDoc.readByte();
this.putMemo(i, false);
break;
case 114:
i = this.binaryDoc.readIntLE();
this.putMemo(i, true);
break;
case 104:
i = this.binaryDoc.readByte();
o = this.getMemo(i);
this.push(o == null ? "BINGET" + (++this.id) : o);
break;
case 106:
i = this.binaryDoc.readIntLE();
o = this.getMemo(i);
this.push(o == null ? "LONG_BINGET" + (++this.id) : o);
break;
case 85:
i = this.binaryDoc.readByte() & 0xff;
a =  Clazz.newByteArray (i, 0);
this.binaryDoc.readByteArray(a, 0, i);
this.push(a);
break;
case 84:
i = this.binaryDoc.readIntLE();
a =  Clazz.newByteArray (i, 0);
this.binaryDoc.readByteArray(a, 0, i);
this.push(a);
break;
case 88:
i = this.binaryDoc.readIntLE();
a =  Clazz.newByteArray (i, 0);
this.binaryDoc.readByteArray(a, 0, i);
this.push(a);
break;
case 93:
this.emptyListPt = this.binaryDoc.getPosition() - 1;
this.push( new JU.Lst());
break;
case 99:
l =  new JU.Lst();
l.addLast("global");
l.addLast(this.readStringAsBytes());
l.addLast(this.readStringAsBytes());
this.push(l);
break;
case 98:
o = this.pop();
this.build.addLast(o);
break;
case 40:
this.putMark(this.stack.size());
break;
case 78:
this.push(null);
break;
case 111:
this.push(this.getObjects(this.getMark()));
break;
case 115:
o = this.pop();
var s = this.bytesToString(this.pop());
(this.peek()).put(s, o);
break;
case 117:
mark = this.getMark();
l = this.getObjects(mark);
o = this.peek();
if (Clazz.instanceOf(o,"JU.Lst")) {
for (i = 0; i < l.size(); i++) {
var oo = l.get(i);
(o).addLast(oo);
}
} else {
map = o;
for (i = l.size(); --i >= 0; ) {
o = l.get(i);
var key = this.bytesToString(l.get(--i));
if (this.writer != null) this.dump("key=" + key);
map.put(key, o);
}
}break;
case 46:
going = false;
break;
case 116:
this.push(this.getObjects(this.getMark()));
break;
case 76:
var val =  String.instantialize(this.readStringAsBytes());
if (val.endsWith("L")) {
val = val.substring(0, val.length - 1);
}this.push(Long.$valueOf(val));
break;
case 82:
this.pop();
break;
case 73:
s = this.bytesToString(this.readStringAsBytes());
try {
this.push(Integer.$valueOf(Integer.parseInt(s)));
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
var ll = Long.parseLong(s);
this.push(Integer.$valueOf((ll & 0xFFFFFFFF)));
} else {
throw e;
}
}
break;
case 41:
this.push( new JU.Lst());
break;
default:
JU.Logger.error("Pickle reader error: " + b + " " + this.binaryDoc.getPosition());
}
}
if (logging) this.log("");
JU.Logger.info("PyMOL Pickle reader cached " + this.memo.size() + " tokens; retrieved " + this.retrieveCount);
map = this.stack.removeItemAt(0);
if (map.size() == 0) for (i = this.stack.size(); --i >= 0; ) {
o = this.stack.get(i--);
a = this.stack.get(i);
var key = this.bytesToString(a);
map.put(key, o);
}
this.memo = null;
if (this.writer != null) this.writer.close();
return map;
}, "~B");
Clazz.defineMethod(c$, "addStartlen", 
function(l, mark){
var pt = this.binaryDoc.getPosition();
var filePt = mark.filePt;
var startLen =  new JU.Lst();
startLen.addLast(Integer.$valueOf(filePt));
startLen.addLast(Integer.$valueOf(pt - filePt));
l.addLast(startLen);
}, "JU.Lst,J.adapter.readers.pymol.PickleReader.Mark");
Clazz.defineMethod(c$, "bytesToString", 
function(o){
try {
return (JU.AU.isAB(o) ?  String.instantialize(o, "UTF-8") : o.toString());
} catch (e) {
if (Clazz.exceptionOf(e,"java.io.UnsupportedEncodingException")){
return "";
} else {
throw e;
}
}
}, "~O");
Clazz.defineMethod(c$, "putMemo", 
function(i, doCheck){
var o = this.peek();
if (JU.AU.isAB(o)) o = this.bytesToString(o);
if ((typeof(o)=='string')) {
this.memo.put(Integer.$valueOf(i), o);
}}, "~N,~B");
Clazz.defineMethod(c$, "getMemo", 
function(i){
var o = this.memo.get(Integer.$valueOf(i));
if (o == null) return o;
this.retrieveCount++;
return o;
}, "~N");
Clazz.defineMethod(c$, "getObjects", 
function(mark){
var imark = mark.stackPt;
var n = this.stack.size() - imark;
var args =  new JU.Lst();
args.ensureCapacity(n);
for (var i = imark; i < this.stack.size(); ++i) {
args.addLast(this.stack.get(i));
}
for (var i = this.stack.size(); --i >= imark; ) this.stack.removeItemAt(i);

return args;
}, "J.adapter.readers.pymol.PickleReader.Mark");
Clazz.defineMethod(c$, "readStringAsBytes", 
function(){
var n = 0;
var a = this.aTemp;
while (true) {
var b = this.binaryDoc.readByte();
if (b == 0xA) break;
if (n >= a.length) a = this.aTemp = JU.AU.arrayCopyByte(a, a.length * 2);
a[n++] = b;
}
var ret = JU.AU.arrayCopyByte(a, n);
if (this.writer != null) this.dump("String=" +  String.instantialize(ret));
return ret;
});
Clazz.defineMethod(c$, "putMark", 
function(i){
if (this.logging) this.log("\n " + Integer.toHexString(this.binaryDoc.getPosition()) + " [");
this.marks.addLast(Clazz.innerTypeInstance(J.adapter.readers.pymol.PickleReader.Mark, this, null, i, this.emptyListPt));
this.markCount++;
switch (this.markCount) {
case 2:
var o = this.stack.get(i - 2);
if (JU.AU.isAB(o)) {
this.thisSection = this.bytesToString(o);
this.inMovie = "movie".equals(this.thisSection);
this.inNames = "names".equals(this.thisSection);
}break;
default:
break;
}
}, "~N");
Clazz.defineMethod(c$, "getMark", 
function(){
return this.marks.removeItemAt(--this.markCount);
});
Clazz.defineMethod(c$, "push", 
function(o){
if (this.writer != null) {
this.dump("push", o);
}if (this.logging && ((typeof(o)=='string') || Clazz.instanceOf(o, Double) || Clazz.instanceOf(o, Integer))) this.log(((typeof(o)=='string') ? "'" + o + "'" : o) + ", ");
this.stack.addLast(o);
}, "~O");
Clazz.defineMethod(c$, "dump", 
function(key, o){
if (this.writer == null) return;
var s = null;
if (o == null) {
s = "null";
} else if ((typeof(o)=='string') || Clazz.instanceOf(o, Number) || Clazz.instanceOf(o, Boolean)) {
s = (o === "" ? "\"\"" : o.toString());
} else if (Clazz.instanceOf(o,Array)) {
var b = o;
if (b.length > 0 && b.length % 56 == 0) {
this.dump("b56:" + java.util.Arrays.toString(b));
}if (b.length > 0 && b[0] >= 32 && b[0] <= 126) {
s = "bytes=" + b + " " +  String.instantialize(b);
} else if (b.length > 100) {
s = "byte[" + b.length + "]";
} else {
s = "bytes=" + java.util.Arrays.toString(b);
}}if (s == null) s = o.getClass().getName();
this.dump(key + " " + s);
}, "~S,~O");
Clazz.defineMethod(c$, "dump", 
function(string){
if (this.writer == null) return;
try {
this.writer.write(this.binaryDoc.getPosition() + "\t");
this.writer.write(string + "\n");
} catch (e) {
if (Clazz.exceptionOf(e, Exception)){
} else {
throw e;
}
}
}, "~S");
Clazz.defineMethod(c$, "peek", 
function(){
return this.stack.get(this.stack.size() - 1);
});
Clazz.defineMethod(c$, "pop", 
function(){
if (this.writer != null) this.dump("pop");
return this.stack.removeItemAt(this.stack.size() - 1);
});
c$.$PickleReader$Mark$ = function(){
/*if4*/;(function(){
var c$ = Clazz.decorateAsClass(function(){
Clazz.prepareCallback(this, arguments);
this.stackPt = 0;
this.filePt = 0;
Clazz.instantialize(this, arguments);}, J.adapter.readers.pymol.PickleReader, "Mark", null);
Clazz.makeConstructor(c$, 
function(mark, filePt){
this.stackPt = mark;
this.filePt = filePt;
}, "~N,~N");
Clazz.overrideMethod(c$, "toString", 
function(){
return "[Mark " + this.stackPt + " " + this.filePt + "]";
});
/*eoif4*/})();
};
});
;//5.0.1-v7 Mon Aug 24 10:01:38 CDT 2026
