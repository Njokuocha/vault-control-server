export namespace main {
	
	export class Metrics {
	    cpuPercent: number;
	    memoryUsedMB: number;
	    memoryTotalMB: number;
	    activeConnections: number;
	    uptime: string;
	
	    static createFrom(source: any = {}) {
	        return new Metrics(source);
	    }
	
	    constructor(source: any = {}) {
	        if ('string' === typeof source) source = JSON.parse(source);
	        this.cpuPercent = source["cpuPercent"];
	        this.memoryUsedMB = source["memoryUsedMB"];
	        this.memoryTotalMB = source["memoryTotalMB"];
	        this.activeConnections = source["activeConnections"];
	        this.uptime = source["uptime"];
	    }
	}
	export class TableRows {
	    columns: string[];
	    rows: any[][];
	
	    static createFrom(source: any = {}) {
	        return new TableRows(source);
	    }
	
	    constructor(source: any = {}) {
	        if ('string' === typeof source) source = JSON.parse(source);
	        this.columns = source["columns"];
	        this.rows = source["rows"];
	    }
	}

}

