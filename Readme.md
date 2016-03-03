some thoughts

client
- dropzone
- add some data to the dropped files in the zone
- select an object to bind the dropped files
- submit form
 
server
- handles form submission
- takes care of file storage
- create new entry for each file
- links the entries to the object

layout 

- type object selector 
    -> selecting adds the selected object type to the automcomplete 
- object autocomplete -> select2 -> remote data(ajax.php loads FileOwnerBrowser) + object type
- dropzone div to drop files
- file list 
    -> each dropped file adds to the form
        -> show file name    
        -> change name inputfield
        -> select date field.
- some form validation
- submit          
        
             