<form id="users_cumulate_form" class="form-inline">
    <div class="form-group margin-r-5">
        <label>维度:</label>
        <select id="users_cumulate_date_type" name="users_cumulate_date_type" class="form-control">
            <option value="day" selected>天</option>
            <option value="month">月</option>
            <option value="year">年</option>
        </select>
    </div>

    <div class="form-group margin-r-5">
        <label>区间:</label>
        <div class="input-group">
            <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
            <input id="users_cumulate_started_at" type="text" name="users_cumulate_started_at" class="form-control started_at">
        </div>
        <div class="input-group">
            <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
            <input id="users_cumulate_ended_at" type="text" name="users_cumulate_ended_at" class="form-control ended_at">
        </div>
    </div>

    <button id="users_cumulate_submit" type="submit" class="btn btn-primary">提交</button>

</form>
<hr>

<div id="users_cumulate" style="height:400px;"></div>


<script>

    $(document).ready(function () {
        users_cumulateDataInit();
    });


    /*
     * 用户统计数据相关初始化
     */
    function users_cumulateDataInit() {

        //日历控件默认配置
        let dateOptions = {
            format: 'YYYY-MM-DD',
            locale: '{{config("app.locale")}}',
            minDate: '2015-12-01',
            maxDate: moment().format('YYYY-MM-DD')
        };

        //Chart 对象
        let chart=null;
        // 指定图表的配置项和数据
        let chartOption=null;


        initData();

        //维度选择事件
        $("#users_cumulate_date_type").on("change", function () {
            // console.log($(this).val());
            let startDate;
            let endDate;
            switch ($(this).val()) {
                case 'day':
                    startDate = moment().add(-31, 'days').format('YYYY-MM-DD');
                    endDate = moment().format('YYYY-MM-DD');
                    dateOptions.format = 'YYYY-MM-DD';
                    dateOptions.minDate = '2015-12-01';
                    break;
                case 'month':
                    startDate = moment().add(-12, 'months').format('YYYY-MM');
                    endDate = moment().format('YYYY-MM');
                    dateOptions.format = 'YYYY-MM';
                    dateOptions.minDate = '2015-12';
                    break;
                case 'year':
                    // startDate = moment().add(-10, 'years').format('YYYY');
                    startDate='2015';
                    endDate = moment().format('YYYY');
                    dateOptions.format = 'YYYY';
                    dateOptions.minDate = '2015';
                    break;
            }

            $('#users_cumulate_started_at').data("DateTimePicker").destroy();
            $('#users_cumulate_ended_at').data("DateTimePicker").destroy();


            $('#users_cumulate_started_at').datetimepicker(Object.assign(dateOptions, {
                date: startDate
            }));

            $('#users_cumulate_ended_at').datetimepicker(Object.assign(dateOptions, {
                date: endDate
            }));

            //重新请求数据
            const users_cumulateForm = new FormData(document.getElementById("users_cumulate_form"));
            requestUserCumulateData(users_cumulateForm);
        });


        //提交按钮点击事件
        $("#users_cumulate_submit").on('click', function () {
            const users_cumulateForm = new FormData(document.getElementById("users_cumulate_form"));
            requestUserCumulateData(users_cumulateForm);

            return false;
        });


        //初始化图表数据
        function initData() {
            const defaultStarted = moment().add(-31, 'days').format('YYYY-MM-DD');
            const defaultEnded = moment().format('YYYY-MM-DD');

            $('#users_cumulate_started_at').datetimepicker(Object.assign(dateOptions, {
                defaultDate: defaultStarted
            }));

            $('#users_cumulate_ended_at').datetimepicker(Object.assign(dateOptions, {
                defaultDate: defaultEnded
            }));

             chartOption = {
                title: {
                    // text: '累计用户'
                },
                toolbox: {
                    show: true,
                    feature: {
                        magicType: {type: ['line', 'bar']},
                        restore: {},
                        saveAsImage: {}
                    }
                },
                tooltip: {
                    trigger: 'axis',
                    axisPointer: {
                        type: 'cross'
                    },
                },
                legend: {
                    data: ['微信订阅用户', '累计用户']
                },
                xAxis: {
                    type: 'category',
                },
                yAxis: {
                    name: '用户数',
                    min: "dataMin"
                },
                series: [{
                    name: '微信订阅用户',
                    type: 'line',
                    markPoint: {
                        data: [
                            {type: 'max', name: '最大值'},
                            {type: 'min', name: '最小值'}
                        ]
                    },
                    // markLine: {
                    //     data: [
                    //         {type: 'average', name: '平均值'}
                    //     ]
                    // }
                }, {
                    name: '累计用户',
                    type: 'line',
                    markPoint: {
                        data: [
                            {type: 'max', name: '最大值'},
                            {type: 'min', name: '最小值'}
                        ]
                    },
                    // markLine: {
                    //     data: [
                    //         {type: 'average', name: '平均值'}
                    //     ]
                    // }
                }]
            };

            chart = echarts.init(document.getElementById('users_cumulate'), 'walden');
            window.addEventListener('resize',() => chart.resize(),false);



            //默认请求数据,时间纬度:天;时间范围:最近31天
            let users_cumulateForm = new FormData();
            users_cumulateForm.append("users_cumulate_date_type", "day");
            users_cumulateForm.append("users_cumulate_started_at", defaultStarted);
            users_cumulateForm.append("users_cumulate_ended_at", defaultEnded);
            if ($('#subject_uuid').val() !== undefined) {
                users_cumulateForm.append("subject_uuid", $('#subject_uuid').val());
            }

            requestUserCumulateData(users_cumulateForm);
        }


        /**
         * 请求微信累计用户数据
         * @param form
         */
        function requestUserCumulateData(form) {

            let wechatUserData = null;
            let userData = null;

            if ($('#subject_uuid').val() !== undefined) {
                form.append("subject_uuid", $('#subject_uuid').val());
            }

            let wechatUserRequest = doAjaxForForm("/admin/statistics/wechat_user/cumulate", "POST",
                form, function (data) {
                    wechatUserData = data;
                });


            let userRequest = doAjaxForForm("/admin/statistics/users/cumulate", "POST",
                form, function (data) {
                    userData = data;
                });

            $.when(wechatUserRequest, userRequest)
                .done(function () {
                    let renderData = Array.from(
                        wechatUserData
                            .concat(userData)
                            .reduce(
                                (m, x) =>
                                    m.set(x.ref_date, Object.assign(m.get(x.ref_date) || {}, x)),
                                new Map()
                            )
                            .values());

                    // console.log(renderData);

                    if (renderData && renderData.length > 0) {
                        chartOption.dataset = {
                            source: renderData
                        };
                        chart.setOption(chartOption);
                    }else{
                        chart.clear();
                    }

                })
                .fail(function () {
                    alert("加载数据失败,请刷新重试");
                });
        }


    }


</script>
