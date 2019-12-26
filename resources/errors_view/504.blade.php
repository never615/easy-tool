@extends('errors.error_base')

@section('title','504 Gateway Timeout')

@section('content')
    <div class="container">
        <!-- Jumbotron -->
        <div class="jumbotron">
            <h1><i class="fa fa-clock-o orange"></i> 504 Gateway Timeout</h1>
            <p class="lead">The web server is returning an unexpected networking error for <em><span
                        id="display-domain"></span></em>.</p>
            <a href="javascript:document.location.reload(true);" class="btn btn-default btn-lg text-center"><span
                    class="text-success">Try This Page Again</span></a>
        </div>
    </div>
    <div class="container">
        <div class="body-content">

            <!-- Example row of columns -->
            <div class="row">
                <div class="col-md-6">
                    <h2>What happened?</h2>
                    <p class="lead">A 504 error status implies there is a slow IP communication problem between back-end
                        servers attempting to fulfill this request.</p>
                </div>
                <div class="col-md-6">
                    <h2>What can I do?</h2>
                    <p class="lead">If you're a site visitor</p>
                    <p><a onclick=javascript:checkSite();>Check to see if this website down for everyone or just
                            you.</a>
                        <script type="text/javascript">
                            function checkSite() {
                                var currentSite = window.location.hostname;
                                window.location = "http://isup.me/" + currentSite;
                            }
                        </script>
                    </p>
                    <p>Also, clearing your browser cache and refreshing the page may clear this issue. If the problem
                        persists and you need immediate assistance, please send us an email instead.</p>
                    <p class="lead">If you're the site owner</p>
                    <p>Clearing your browser cache and refreshing the page may clear this issue. If the problem persists
                        and you need immediate assistance, please contact your website provider.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
