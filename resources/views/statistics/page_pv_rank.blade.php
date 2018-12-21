<div id="page_pv_rank">

    <form id="page_pv_rank_form" class="form-inline">
        <div class="form-group margin-r-5">
            <label>维度:</label>
            <select id="page_pv_rank_date_type" name="date_type" class="form-control">
                <option value="day">天</option>
                <option value="month">月</option>
                <option value="year">年</option>
            </select>
        </div>

        <div class="form-group margin-r-5">
            <label>时间:</label>
            <div class="input-group">
                <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                <input id="page_pv_rank_date" type="text" name="date" class="form-control started_at">
            </div>
        </div>
        <button id="page_pv_rank_submit" type="submit" class="btn btn-primary">提交</button>
    </form>
    <hr>

    <div id="chart" style="height:400px;"></div>
</div>


<script>

    $(document).ready(function () {
        page_pv_rank_DataInit();
    });


    function page_pv_rank_DataInit() {
        //Chart 对象
        let chart = null;
        // 指定图表的配置项和数据
        let chartOption = null;


        dateChoiceInit('day');
        chartInit();
        const newForm = new FormData($("#page_pv_rank #page_pv_rank_form")[0]);
        requestNewData(newForm);


        //维度选择事件
        $("#page_pv_rank #page_pv_rank_date_type").on("change", function () {
            // console.log($(this).val());

            updateDateChoice($(this).val());

            //重新请求数据
            const newForm = new FormData($("#page_pv_rank #page_pv_rank_form")[0]);
            requestNewData(newForm);
        });


        //提交按钮点击事件
        $("#page_pv_rank #page_pv_rank_submit").on('click', function () {
            const newForm = new FormData($("#page_pv_rank #page_pv_rank_form")[0]);
            requestNewData(newForm);

            return false;
        });


        /**
         * 请求数据
         * @param form
         */
        function requestNewData(form) {
            //页面访问热度数据
            let pagePvRankData = null;


            if ($('#subject_uuid').val() !== undefined) {
                form.append("subject_uuid", $('#subject_uuid').val());
            }

            let pagePvRankDataRequest = doAjaxForForm("/admin/statistics/page/pv/rank", "POST",
                form, function (data) {
                    pagePvRankData = data;
                });


            $.when(pagePvRankDataRequest)
                .done(function () {
                    let renderData = Array.from(pagePvRankData.values());

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
                    // alert("加载数据失败,请刷新重试");
                });
        }


        //根据纬度的变化,更新日期控件
        function updateDateChoice(type) {
            let date;
            //日历控件默认配置
            let dateOptions = {
                // format: 'YYYY-MM-DD',
                locale: '{{config("app.locale")}}',
                // minDate: '2015-12-01',
                maxDate: moment().format('YYYY-MM-DD')
            };

            const defaultDate = moment().add(-1, 'days').format('YYYY-MM-DD');

            switch (type) {
                case 'day':
                    date = moment().add(-1, 'days').format('YYYY-MM-DD');
                    dateOptions.format = 'YYYY-MM-DD';
                    dateOptions.minDate = '2018-10-01';
                    break;
                case 'month':
                    date = moment().add(-1, 'months').format('YYYY-MM');
                    dateOptions.format = 'YYYY-MM';
                    dateOptions.minDate = '2018-10';
                    break;
                case 'year':
                    date = '2015';

                    dateOptions.format = 'YYYY';
                    dateOptions.minDate = '2018';

                    break;
            }

            if ($('#page_pv_rank #page_pv_rank_date').data("DateTimePicker")) {
                $('#page_pv_rank #page_pv_rank_date').data("DateTimePicker").destroy();
            }


            $('#page_pv_rank #page_pv_rank_date').datetimepicker(Object.assign(dateOptions, {
                defaultDate: defaultDate,
                date: date
            }));
        }

        //时间选择控件初始化
        function dateChoiceInit(type) {
            //设置默认的时间维度选择
            //month
            $("#page_pv_rank #page_pv_rank_date_type option[value='" + type + "']").prop("selected", 'selected');
            updateDateChoice(type);
        }


        /**
         * 表格初始化
         */
        function chartInit() {

            chartOption = {
                title: {},
                tooltip: {
                    trigger: 'axis',
                    axisPointer: {
                        type: 'shadow'
                    },
                },
                legend: {
                    data: ['页面访问量']
                },
                xAxis: {
                    name: '页面访问量',
                    type: 'value',
                },
                yAxis: {
                    name: '页面',
                    type: "category"
                },
                series: [{
                    name: '页面访问量',
                    type: 'bar',
                }]
            };

            chart = echarts.init($("#page_pv_rank #chart")[0], 'walden');

            window.addEventListener('resize', () => chart.resize(), false);
        }
    }


</script>
