<html>
<head>
    <script src="assets/js/dropzone.js"></script>
</head>
<body>
<div id="uploader">
    <div id="dropzone" style="height: 100px; width: 300px; border: 1px solid blue"></div>
    <div id="fileOwner">
        <input type="hidden" name="owner_id" value="998"/>
    </div>
    <div class="files" id="previews">
        <div id="template" class="file-row">
            <div>
                <span class="preview"><img data-dz-thumbnail/></span>
            </div>
            <div>
                <p class="name" data-dz-name></p>
                <strong class="error text-danger" data-dz-errormessage></strong>
            </div>
            <div>
                <input type="text" class="date"/>
                <input type="text" class="description"/>
            </div>
            <div>
                <p class="size" data-dz-size></p>
            </div>
            <div>
                <button class="start">
                    <span>Start</span>
                </button>
                <button data-dz-remove class="cancel">
                    <span>Cancel</span>
                </button>
            </div>
        </div>
    </div>

    <button id="upload"><span>Upload</span></button>
</div>
    <script type="text/javascript">
        var previewNode = document.querySelector("#template");
        previewNode.id = "";
        var previewTemplate = previewNode.parentNode.innerHTML;
        previewNode.parentNode.removeChild(previewNode);

        var myDropzone = new Dropzone('div#uploader', {
            url: "/target-url",
            thumbnailWidth: 80,
            thumbnailHeight: 80,
            parallelUploads: 20,
            previewTemplate: previewTemplate,
            autoQueue: false,
            previewsContainer: "#previews",
            clickable: "#dropzone"
        });

        myDropzone.on("addedfile", function(file) {
            file.previewElement.querySelector(".start").onclick = function() { myDropzone.enqueueFile(file); };
        });

        myDropzone.on("sending", function(file, xhr, formData) {
            formData.append("date", file.previewElement.querySelector('input.date').value);
            formData.append("description", file.previewElement.querySelector('input.description').value);
            formData.append("owner", document.querySelector('div#fileOwner input').value);
            file.previewElement.querySelector(".start").setAttribute("disabled", "disabled");
        });

        document.querySelector("#upload").onclick = function() {
            myDropzone.enqueueFiles(myDropzone.getFilesWithStatus(Dropzone.ADDED));
        };
    </script>
</body>
</html>