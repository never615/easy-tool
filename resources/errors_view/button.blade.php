<div class="row">
    <p><a onclick=javascript:checkSite1(); class="btn btn-default btn-lg green">Go Home</a>
        <script type="text/javascript">
            function checkSite1() {
                var currentSite = window.location.hostname;
                window.location = "http://" + currentSite;
            }
        </script>
    </p>
    <p><a onclick=javascript:checkSite2(); class="btn btn-default btn-lg green">Go Previous Page</a>
        <script type="text/javascript">
            function checkSite2() {
                history.back();
            }
        </script>
    </p>
</div>